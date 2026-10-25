<?php

namespace App\Filament\Resources\FellowshipResource\RelationManagers;

use App\Enums\QuizDifficulty;
use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipQuestion;
use App\Support\Fellowship\FellowshipQuestionFormFields;
use App\Support\Fellowship\SavesQuestionFilterValues;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The Fellowship's question bank.
 *
 * Questions normally arrive by import; this table is for reading them,
 * correcting one, and removing one. Deletes are soft, and nothing here can
 * disturb a session a student has already generated — sessions serve their own
 * snapshots, so an edit or a delete only affects sessions generated afterwards.
 *
 * There is no bulk delete: a bulk query delete would bypass the model, and
 * removing a swathe of a question bank is not something to do by accident.
 */
class QuestionsRelationManager extends RelationManager
{
    use SavesQuestionFilterValues;

    protected static string $relationship = 'questions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.fellowship.questions_section');
    }

    /**
     * The owner's View page is the workspace for these records, so the table
     * stays writable there; the model policies still decide who may act.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Fellowship $fellowship */
        $fellowship = $this->getOwnerRecord();

        return $schema->components(FellowshipQuestionFormFields::for($fellowship));
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.fellowship.question_section_overview'))
                ->schema([
                    TextEntry::make('title')
                        ->label(__('admin.fellowship.field_title'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('question_text')
                        ->label(__('admin.fellowship.field_question_text'))
                        ->columnSpanFull(),
                    TextEntry::make('difficulty')
                        ->label(__('admin.fellowship.field_difficulty'))
                        ->badge(),
                    TextEntry::make('performanceTopic.value')
                        ->label(__('admin.fellowship.field_performance_topic'))
                        ->placeholder('—')
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('explanation')
                        ->label(__('admin.fellowship.field_explanation'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Fellowship $fellowship */
        $fellowship = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('question_text')
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.fellowship.field_title'))
                    ->placeholder('—')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('question_text')
                    ->label(__('admin.fellowship.field_question_text'))
                    ->searchable()
                    ->limit(70)
                    ->wrap(),
                TextColumn::make('difficulty')
                    ->label(__('admin.fellowship.field_difficulty'))
                    ->badge()
                    ->color(fn (QuizDifficulty $state): string => self::difficultyColor($state))
                    ->sortable(),
                TextColumn::make('performanceTopic.value')
                    ->label(__('admin.fellowship.field_performance_topic'))
                    ->placeholder(__('admin.fellowship.performance_topic_unset'))
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('options_count')
                    ->label(__('admin.fellowship.field_options_count'))
                    ->counts('options')
                    ->badge(),
                TextColumn::make('correct_answer')
                    ->label(__('admin.fellowship.field_correct_answer'))
                    ->state(fn (FellowshipQuestion $record): ?string => $record->options->firstWhere('is_correct', true)?->option_text)
                    ->placeholder('—')
                    ->limit(50),
                IconColumn::make('has_explanation')
                    ->label(__('admin.fellowship.field_explanation'))
                    ->boolean()
                    ->state(fn (FellowshipQuestion $record): bool => $record->explanation !== null && $record->explanation !== ''),
            ])
            ->filters([
                SelectFilter::make('difficulty')
                    ->label(__('admin.fellowship.field_difficulty'))
                    ->options(self::difficultyOptions()),
                SelectFilter::make('performance_filter_value_id')
                    ->label(__('admin.fellowship.field_performance_topic'))
                    ->options(fn (): array => $fellowship->performanceFilter
                        ?->values()
                        ->pluck('value', 'id')
                        ->all() ?? []),
                TrashedFilter::make(),
            ])
            ->headerActions([
                // The dynamic filter selects are not columns, so they are
                // stripped from the payload and written against the saved
                // question afterwards.
                CreateAction::make()
                    // Every question carries exactly one Performance Topic, so
                    // there is nothing to author until the Fellowship has that
                    // classification with a value under it. Blocked here, with
                    // the reason, rather than behind a required select the
                    // admin has no way of filling.
                    ->disabled(fn (): bool => self::authoringBlockedReason($fellowship) !== null)
                    ->tooltip(fn (): ?string => self::authoringBlockedReason($fellowship))
                    ->using(function (array $data) use ($fellowship): FellowshipQuestion {
                        ['data' => $attributes, 'filters' => $filters] = $this->extractFilterValues($data);

                        /** @var FellowshipQuestion $question */
                        $question = $fellowship->questions()->create($attributes);

                        $this->saveFilterValues($question, $filters);

                        return $question;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, FellowshipQuestion $record): array => [
                        ...$data,
                        ...FellowshipQuestionFormFields::filterStateFor($record),
                    ])
                    ->using(function (FellowshipQuestion $record, array $data): FellowshipQuestion {
                        ['data' => $attributes, 'filters' => $filters] = $this->extractFilterValues($data);

                        $record->update($attributes);
                        $this->saveFilterValues($record, $filters);

                        return $record;
                    }),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * Why a question cannot be written by hand yet, or null when it can.
     *
     * The classification is designated either by the first confirmed import or
     * by hand on the Filters tab; either way a question needs a value of it to
     * be filed under.
     */
    public static function authoringBlockedReason(Fellowship $fellowship): ?string
    {
        /** @var FellowshipFilter|null $filter */
        $filter = $fellowship->performanceFilter()->first();

        if ($filter === null) {
            return __('admin.fellowship.question_needs_performance_filter');
        }

        if (! $filter->values()->exists()) {
            return __('admin.fellowship.question_needs_performance_topic_value');
        }

        return null;
    }

    /** @return array<string, string> */
    public static function difficultyOptions(): array
    {
        return [
            QuizDifficulty::Easy->value => __('admin.learning.difficulty_easy'),
            QuizDifficulty::Medium->value => __('admin.learning.difficulty_medium'),
            QuizDifficulty::Hard->value => __('admin.learning.difficulty_hard'),
        ];
    }

    public static function difficultyColor(QuizDifficulty $difficulty): string
    {
        return match ($difficulty) {
            QuizDifficulty::Easy => 'success',
            QuizDifficulty::Medium => 'warning',
            QuizDifficulty::Hard => 'danger',
        };
    }
}
