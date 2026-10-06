<?php

namespace App\Filament\Resources\QuizResource\Pages;

use App\Enums\QuizDifficulty;
use App\Filament\Resources\ChapterResource;
use App\Filament\Resources\QuizResource;
use App\Filament\Resources\SubjectResource;
use App\Models\Quiz;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Quiz workspace: overview plus the quiz's own questions, rendered below by
 * QuestionsRelationManager.
 */
class ViewQuiz extends ViewRecord
{
    protected static string $resource = QuizResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function getBreadcrumbs(): array
    {
        $chapter = $this->record->chapter;

        return [
            SubjectResource::getUrl('index') => __('admin.learning.subjects_plural_model_label'),
            SubjectResource::getUrl('view', ['record' => $chapter->subject_id]) => $chapter->subject?->name,
            ChapterResource::getUrl('view', ['record' => $chapter]) => $chapter->title,
            $this->record->title,
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make(__('admin.learning.quiz_no_questions_warning_heading'))
                    ->key('quiz-no-questions-warning')
                    ->warning()
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->description(__('admin.learning.quiz_no_questions_warning'))
                    ->visible(fn (Quiz $record): bool => ! $record->hasQuestions()),

                Section::make(__('admin.learning.quiz_section_overview'))
                    ->key('quiz-overview')
                    ->schema([
                        TextEntry::make('title')
                            ->label(__('admin.learning.field_quiz_title')),
                        TextEntry::make('chapter.title')
                            ->label(__('admin.learning.chapters_model_label'))
                            ->url(fn (Quiz $record): string => ChapterResource::getUrl('view', ['record' => $record->chapter_id])),
                        TextEntry::make('subject_name')
                            ->label(__('admin.learning.field_subject'))
                            ->state(fn (Quiz $record): ?string => $record->subject()?->name)
                            ->url(fn (Quiz $record): ?string => $record->subject()
                                ? SubjectResource::getUrl('view', ['record' => $record->subject()])
                                : null),
                        TextEntry::make('difficulty')
                            ->label(__('admin.learning.field_quiz_difficulty'))
                            ->badge()
                            ->color(fn (QuizDifficulty $state): string => QuizResource::difficultyColor($state))
                            ->formatStateUsing(fn (QuizDifficulty $state): string => QuizResource::difficultyLabel($state)),
                        TextEntry::make('questions_count')
                            ->label(__('admin.learning.field_questions_count'))
                            ->state(fn (Quiz $record): int => $record->questions()->count())
                            ->badge()
                            ->color(fn (Quiz $record): string => $record->hasQuestions() ? 'success' : 'warning'),
                        TextEntry::make('created_at')
                            ->label(__('admin.learning.field_created_at'))
                            ->dateTime(),
                    ])
                    ->columns(3),
            ]);
    }
}
