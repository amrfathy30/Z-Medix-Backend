<?php

namespace App\Filament\Resources\QuestionResource\Pages;

use App\Filament\Resources\ChapterResource;
use App\Filament\Resources\QuestionResource;
use App\Filament\Resources\QuizResource;
use App\Filament\Resources\SubjectResource;
use App\Models\Question;
use App\Models\QuestionOption;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewQuestion extends ViewRecord
{
    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function getBreadcrumbs(): array
    {
        $quiz = $this->record->quiz;
        $chapter = $quiz->chapter;

        return [
            SubjectResource::getUrl('index') => __('admin.learning.subjects_plural_model_label'),
            SubjectResource::getUrl('view', ['record' => $chapter->subject_id]) => $chapter->subject?->name,
            ChapterResource::getUrl('view', ['record' => $chapter]) => $chapter->title,
            QuizResource::getUrl('view', ['record' => $quiz]) => $quiz->title,
            __('admin.learning.questions_model_label'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.learning.question_section_overview'))
                    ->key('question-overview')
                    ->schema([
                        TextEntry::make('question')
                            ->label(__('admin.learning.field_question_text'))
                            ->columnSpanFull(),
                        TextEntry::make('quiz.title')
                            ->label(__('admin.learning.field_quiz_title'))
                            ->url(fn (Question $record): string => QuizResource::getUrl('view', ['record' => $record->quiz_id])),
                        TextEntry::make('chapter_title')
                            ->label(__('admin.learning.field_chapter_title'))
                            ->state(fn (Question $record): ?string => $record->quiz?->chapter?->title)
                            ->url(fn (Question $record): ?string => $record->quiz?->chapter
                                ? ChapterResource::getUrl('view', ['record' => $record->quiz->chapter])
                                : null),
                        TextEntry::make('subject_name')
                            ->label(__('admin.learning.field_subject'))
                            ->state(fn (Question $record): ?string => $record->quiz?->chapter?->subject?->name)
                            ->url(fn (Question $record): ?string => $record->quiz?->chapter?->subject
                                ? SubjectResource::getUrl('view', ['record' => $record->quiz->chapter->subject])
                                : null),
                        TextEntry::make('correct_answer')
                            ->label(__('admin.learning.field_correct_answer'))
                            ->state(fn (Question $record): ?string => $record->correctOption()?->option_text)
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make(__('admin.learning.question_section_options'))
                    ->key('question-options')
                    ->schema([
                        RepeatableEntry::make('options')
                            ->label(__('admin.learning.question_section_options'))
                            ->schema([
                                TextEntry::make('order')
                                    ->label(__('admin.learning.field_option_order')),
                                TextEntry::make('option_text')
                                    ->label(__('admin.learning.field_option_text')),
                                IconEntry::make('is_correct')
                                    ->label(__('admin.learning.field_option_is_correct'))
                                    ->boolean()
                                    ->tooltip(fn (QuestionOption $record): ?string => $record->is_correct
                                        ? __('admin.learning.field_correct_answer')
                                        : null),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }
}
