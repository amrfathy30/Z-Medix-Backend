<?php

namespace App\Filament\Resources\ChapterResource\Pages;

use App\Enums\ContentStatus;
use App\Enums\QuizDifficulty;
use App\Filament\Resources\ChapterResource;
use App\Filament\Resources\QuizResource;
use App\Filament\Resources\SubjectResource;
use App\Models\Chapter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Chapter workspace: overview, the chapter's single quiz, and (rendered below by
 * PagesRelationManager) its study pages.
 *
 * The quiz is created with the chapter and follows its lifecycle, so there is no
 * create, delete or restore action here — only a defensive warning for legacy
 * records whose quiz is missing, and a warning while the quiz has no questions.
 */
class ViewChapter extends ViewRecord
{
    protected static string $resource = ChapterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            SubjectResource::getUrl('index') => __('admin.learning.subjects_plural_model_label'),
            SubjectResource::getUrl('view', ['record' => $this->record->subject_id]) => $this->record->subject?->name,
            $this->record->title,
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.learning.chapter_section_overview'))
                    ->key('chapter-overview')
                    ->schema([
                        TextEntry::make('title')
                            ->label(__('admin.learning.field_chapter_title')),
                        TextEntry::make('subject.name')
                            ->label(__('admin.learning.field_subject'))
                            ->url(fn (Chapter $record): string => SubjectResource::getUrl('view', ['record' => $record->subject_id])),
                        TextEntry::make('status')
                            ->label(__('admin.learning.field_status'))
                            ->badge()
                            ->color(fn (ContentStatus $state): string => SubjectResource::statusColor($state))
                            ->formatStateUsing(fn (ContentStatus $state): string => SubjectResource::statusLabel($state)),
                        TextEntry::make('order')
                            ->label(__('admin.learning.field_chapter_order')),
                        TextEntry::make('pages_count')
                            ->label(__('admin.learning.field_pages_count'))
                            ->state(fn (Chapter $record): int => $record->pages()->count())
                            ->badge(),
                        TextEntry::make('quiz_title')
                            ->label(__('admin.learning.field_has_quiz'))
                            ->state(fn (Chapter $record): ?string => $record->quiz?->title)
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label(__('admin.learning.field_created_at'))
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label(__('admin.learning.field_updated_at'))
                            ->dateTime(),
                    ])
                    ->columns(3),

                Section::make(__('admin.learning.quiz_section'))
                    ->key('quiz-section')
                    ->schema([
                        // Defensive only: every chapter created through the admin
                        // owns a quiz, so this state means legacy or repaired data.
                        EmptyState::make(__('admin.learning.quiz_missing_heading'))
                            ->key('quiz-missing-state')
                            ->description(__('admin.learning.quiz_missing_description'))
                            ->icon(Heroicon::OutlinedExclamationTriangle)
                            ->iconColor('danger')
                            ->visible(fn (Chapter $record): bool => $record->quiz === null),

                        Callout::make(__('admin.learning.quiz_no_questions_warning_heading'))
                            ->key('quiz-no-questions-warning')
                            ->warning()
                            ->icon(Heroicon::OutlinedExclamationTriangle)
                            ->description(__('admin.learning.quiz_no_questions_warning'))
                            ->visible(fn (Chapter $record): bool => $record->quiz !== null && ! $record->isPublishable()),

                        Grid::make(3)
                            ->key('quiz-summary')
                            ->visible(fn (Chapter $record): bool => $record->quiz !== null)
                            ->schema([
                                TextEntry::make('quiz.title')
                                    ->label(__('admin.learning.field_quiz_title')),
                                TextEntry::make('quiz.difficulty')
                                    ->label(__('admin.learning.field_quiz_difficulty'))
                                    ->badge()
                                    ->color(fn (?QuizDifficulty $state): string => $state ? QuizResource::difficultyColor($state) : 'gray')
                                    ->formatStateUsing(fn (?QuizDifficulty $state): ?string => $state ? QuizResource::difficultyLabel($state) : null),
                                TextEntry::make('quiz_questions_count')
                                    ->label(__('admin.learning.field_questions_count'))
                                    ->state(fn (Chapter $record): int => $record->quizQuestionCount())
                                    ->badge()
                                    ->color(fn (Chapter $record): string => $record->isPublishable() ? 'success' : 'warning'),
                            ]),

                        Actions::make([
                            Action::make('viewQuiz')
                                ->label(__('admin.learning.action_view_quiz'))
                                ->icon(Heroicon::OutlinedEye)
                                ->visible(fn (Chapter $record): bool => $record->quiz !== null)
                                ->authorize(fn (Chapter $record): bool => $record->quiz !== null && QuizResource::canView($record->quiz))
                                ->url(fn (Chapter $record): ?string => $record->quiz
                                    ? QuizResource::getUrl('view', ['record' => $record->quiz])
                                    : null),

                            Action::make('editQuiz')
                                ->label(__('admin.learning.action_edit_quiz'))
                                ->icon(Heroicon::OutlinedPencilSquare)
                                ->visible(fn (Chapter $record): bool => $record->quiz !== null)
                                ->authorize(fn (Chapter $record): bool => $record->quiz !== null && QuizResource::canEdit($record->quiz))
                                ->url(fn (Chapter $record): ?string => $record->quiz
                                    ? QuizResource::getUrl('edit', ['record' => $record->quiz])
                                    : null),
                        ])->key('quiz-actions'),
                    ]),
            ]);
    }
}
