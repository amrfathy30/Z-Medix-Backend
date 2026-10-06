<?php

namespace App\Filament\Resources\QuestionResource\Pages;

use App\Filament\Resources\ChapterResource;
use App\Filament\Resources\QuestionResource;
use App\Filament\Resources\QuizResource;
use App\Filament\Resources\SubjectResource;
use App\Models\Question;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditQuestion extends EditRecord
{
    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            // A quiz must keep at least one question; the Question model rejects
            // the delete as well, so hiding this is usability, not the rule.
            DeleteAction::make()
                ->visible(fn (Question $record): bool => $record->canBeDeleted())
                ->successRedirectUrl(fn (Question $record): string => QuizResource::getUrl('view', ['record' => $record->quiz_id])),
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
            __('filament-panels::resources/pages/edit-record.breadcrumb'),
        ];
    }
}
