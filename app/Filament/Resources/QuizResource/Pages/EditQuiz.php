<?php

namespace App\Filament\Resources\QuizResource\Pages;

use App\Filament\Resources\ChapterResource;
use App\Filament\Resources\QuizResource;
use App\Filament\Resources\SubjectResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * A quiz follows its chapter's lifecycle, so this page intentionally offers no
 * delete, force-delete or restore action.
 */
class EditQuiz extends EditRecord
{
    protected static string $resource = QuizResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    public function getBreadcrumbs(): array
    {
        $chapter = $this->record->chapter;

        return [
            SubjectResource::getUrl('index') => __('admin.learning.subjects_plural_model_label'),
            SubjectResource::getUrl('view', ['record' => $chapter->subject_id]) => $chapter->subject?->name,
            ChapterResource::getUrl('view', ['record' => $chapter]) => $chapter->title,
            QuizResource::getUrl('view', ['record' => $this->record]) => $this->record->title,
            __('filament-panels::resources/pages/edit-record.breadcrumb'),
        ];
    }
}
