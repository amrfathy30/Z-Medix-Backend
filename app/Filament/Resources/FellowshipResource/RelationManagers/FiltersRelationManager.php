<?php

namespace App\Filament\Resources\FellowshipResource\RelationManagers;

use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipFilterValue;
use App\Models\FellowshipQuestion;
use App\Services\Fellowship\FellowshipFilterService;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The Fellowship's dynamic filters and their values.
 *
 * Filters are normally created by an import, which maps a spreadsheet column
 * onto a new or existing one. This table is for the rest: renaming a filter
 * whose column was badly named, correcting a misspelt value, or adding a value
 * before a question needs it.
 *
 * Two things are deliberately protected. The Performance Topic filter cannot be
 * deleted while it is the Fellowship's designated classification — every
 * question and every piece of topic analytics hangs off it. And a filter still
 * in use by a question cannot be deleted either, since that would silently
 * unclassify those questions.
 */
class FiltersRelationManager extends RelationManager
{
    protected static string $relationship = 'filters';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.fellowship.filters_section');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Fellowship $fellowship */
        $fellowship = $this->getOwnerRecord();

        return $schema->components([
            TextInput::make('name')
                ->label(__('admin.fellowship.field_filter_name'))
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            // The Performance Topic designation. Normally made by the first
            // import, which maps a column onto it; set here when there is no
            // spreadsheet to import — a Fellowship cannot have a question
            // before it has this classification.
            Toggle::make('is_performance')
                ->label(__('admin.fellowship.field_is_performance'))
                ->helperText(__('admin.fellowship.is_performance_toggle_hint'))
                ->default(false)
                // A Fellowship has one Performance Topic classification, and
                // every question's required topic is a value of it. Moving or
                // withdrawing the designation while questions hang off it would
                // leave them answerable to a filter that no longer classifies
                // anything, so it is refused with the reason.
                ->rule(fn (?FellowshipFilter $record): Closure => function (string $attribute, mixed $state, Closure $fail) use ($fellowship, $record): void {
                    $reason = self::designationBlockedReason(
                        $fellowship,
                        $record,
                        filter_var($state, FILTER_VALIDATE_BOOLEAN),
                    );

                    if ($reason !== null) {
                        $fail($reason);
                    }
                })
                ->columnSpanFull(),

            Repeater::make('values')
                ->label(__('admin.fellowship.field_filter_values'))
                ->relationship()
                ->schema([
                    TextInput::make('value')
                        ->label(__('admin.fellowship.field_filter_value'))
                        ->required()
                        ->maxLength(255),
                ])
                ->helperText(__('admin.fellowship.filter_values_helper'))
                ->defaultItems(1)
                // A value a question is still filed under cannot be removed:
                // the database restricts it, and nulling it would silently
                // unclassify those questions. Caught here so the admin is told
                // which values to re-file rather than shown a query error.
                ->rule(fn (?FellowshipFilter $record): Closure => function (string $attribute, mixed $state, Closure $fail) use ($record): void {
                    if ($record === null) {
                        return;
                    }

                    $inUse = self::valuesInUse($record, self::keptValueIds($state));

                    if ($inUse !== []) {
                        $fail(__('admin.fellowship.filter_values_in_use_cannot_be_removed', [
                            'values' => implode(', ', $inUse),
                        ]));
                    }
                })
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Fellowship $fellowship */
        $fellowship = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fellowship.field_filter_name'))
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_performance')
                    ->label(__('admin.fellowship.field_is_performance'))
                    ->boolean()
                    ->tooltip(__('admin.fellowship.is_performance_hint')),
                TextColumn::make('values_count')
                    ->label(__('admin.fellowship.field_filter_values_count'))
                    ->counts('values')
                    ->badge(),
                TextColumn::make('values_list')
                    ->label(__('admin.fellowship.field_filter_values'))
                    ->state(fn (FellowshipFilter $record): string => $record->values->pluck('value')->take(6)->implode(', '))
                    ->placeholder('—')
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('questions_using')
                    ->label(__('admin.fellowship.field_questions_using'))
                    ->state(fn (FellowshipFilter $record): int => self::questionsUsing($record))
                    ->badge()
                    ->color('gray'),
            ])
            ->headerActions([
                // `is_performance` is a column and saves itself; the
                // Fellowship's own pointer at the designated filter is the
                // other half of that one fact and is written afterwards.
                CreateAction::make()
                    ->after(fn (FellowshipFilter $record): null => self::syncDesignation($fellowship, $record)),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(fn (FellowshipFilter $record): null => self::syncDesignation($fellowship, $record)),
                DeleteAction::make()
                    ->visible(fn (FellowshipFilter $record): bool => self::filterCanBeDeleted($record))
                    ->tooltip(fn (FellowshipFilter $record): ?string => self::deleteBlockedReason($record)),
            ])
            ->defaultSort('name');
    }

    /**
     * How many of the Fellowship's questions are filed under this filter —
     * through the pivot for a dynamic filter, through the question's own column
     * for the Performance Topic.
     */
    public static function questionsUsing(FellowshipFilter $record): int
    {
        if ($record->is_performance) {
            // Soft-deleted questions are counted too: they still hold the
            // reference the database restricts a value's deletion on, so this
            // number has to agree with what a delete would actually allow.
            return FellowshipQuestion::query()
                ->withTrashed()
                ->where('fellowship_id', $record->fellowship_id)
                ->whereIn('performance_filter_value_id', $record->values()->select('id'))
                ->count();
        }

        return $record->questionFilterValues()->count();
    }

    /**
     * The ids of the existing values the submitted repeater state still keeps.
     *
     * A repeater keys an item it loaded from a record as `record-<id>` and a
     * row the admin just added with a uuid, which is how it tells the two
     * apart when it saves. Reading the ids back out has to use the same
     * spelling: treating only a bare numeric key as an existing value would
     * find none of them, and every value in use would be reported as being
     * removed on an edit that removed nothing.
     *
     * @return list<int>
     */
    public static function keptValueIds(mixed $state): array
    {
        return collect(is_array($state) ? $state : [])
            ->keys()
            ->map(fn ($key): string => str_starts_with((string) $key, 'record-')
                ? substr((string) $key, strlen('record-'))
                : (string) $key)
            ->filter(fn (string $key): bool => is_numeric($key))
            ->map(fn (string $key): int => (int) $key)
            ->values()
            ->all();
    }

    /**
     * The names of this filter's values that a question still uses and that the
     * submitted repeater state no longer keeps.
     *
     * @param  list<int>  $keptValueIds
     * @return list<string>
     */
    public static function valuesInUse(FellowshipFilter $record, array $keptValueIds): array
    {
        return $record->values()
            ->whereKeyNot($keptValueIds === [] ? [0] : $keptValueIds)
            ->get()
            ->filter(function (FellowshipFilterValue $value) use ($record): bool {
                if ($record->is_performance) {
                    return FellowshipQuestion::query()
                        ->withTrashed()
                        ->where('performance_filter_value_id', $value->getKey())
                        ->exists();
                }

                return $value->questionFilterValues()->exists();
            })
            ->pluck('value')
            ->map(fn ($value): string => (string) $value)
            ->values()
            ->all();
    }

    /**
     * Make the Fellowship's `performance_filter_id` agree with the flag this
     * filter was just saved with.
     */
    public static function syncDesignation(Fellowship $fellowship, FellowshipFilter $filter): null
    {
        app(FellowshipFilterService::class)->applyPerformanceDesignation($fellowship, $filter->refresh());

        return null;
    }

    /**
     * Why this filter's Performance Topic designation may not be changed to
     * `$wantsPerformance`, or null when it may.
     *
     * Designating a filter is free while nothing is filed under the current
     * classification — a Fellowship with no questions yet, or one whose
     * designation was never made. Once questions carry topics, the designation
     * is theirs: moving it would strand every one of them, and Topic
     * Performance would report on a filter no question answers to.
     *
     * `$record` is null while a filter is being created, which can only ever
     * turn a designation on.
     */
    public static function designationBlockedReason(
        Fellowship $fellowship,
        ?FellowshipFilter $record,
        bool $wantsPerformance,
    ): ?string {
        /** @var FellowshipFilter|null $current */
        $current = $fellowship->performanceFilter()->first();

        $isCurrent = $record !== null && $current !== null && $current->is($record);

        if ($wantsPerformance) {
            if ($current === null || $isCurrent) {
                return null;
            }

            return self::questionsUsing($current) > 0
                ? __('admin.fellowship.performance_filter_cannot_be_moved', ['filter' => $current->name])
                : null;
        }

        if ($isCurrent && self::questionsUsing($record) > 0) {
            return __('admin.fellowship.performance_filter_cannot_be_withdrawn');
        }

        return null;
    }

    public static function filterCanBeDeleted(FellowshipFilter $record): bool
    {
        return self::deleteBlockedReason($record) === null;
    }

    /**
     * Why this filter may not be deleted, or null when it may.
     */
    public static function deleteBlockedReason(FellowshipFilter $record): ?string
    {
        /** @var Fellowship|null $fellowship */
        $fellowship = $record->fellowship;

        if ($fellowship !== null && $fellowship->performance_filter_id === $record->getKey()) {
            return __('admin.fellowship.performance_filter_cannot_be_deleted');
        }

        if (self::questionsUsing($record) > 0) {
            return __('admin.fellowship.filter_in_use_cannot_be_deleted');
        }

        return null;
    }
}
