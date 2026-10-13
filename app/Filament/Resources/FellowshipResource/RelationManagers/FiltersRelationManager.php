<?php

namespace App\Filament\Resources\FellowshipResource\RelationManagers;

use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipFilterValue;
use App\Models\FellowshipQuestion;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
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
        return $schema->components([
            TextInput::make('name')
                ->label(__('admin.fellowship.field_filter_name'))
                ->required()
                ->maxLength(255)
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

                    $keptIds = collect(is_array($state) ? $state : [])
                        ->keys()
                        ->filter(fn ($key): bool => is_numeric($key))
                        ->map(fn ($key): int => (int) $key)
                        ->values()
                        ->all();

                    $inUse = self::valuesInUse($record, $keptIds);

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
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
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
