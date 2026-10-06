<?php

namespace App\Filament\Resources\BookResource\Pages;

use App\Filament\Resources\BookResource;
use App\Filament\Resources\SubjectResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditBook extends EditRecord
{
    protected static string $resource = BookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            SubjectResource::getUrl('index') => __('admin.learning.subjects_plural_model_label'),
            SubjectResource::getUrl('view', ['record' => $this->record->subject_id]) => $this->record->subject?->name,
            BookResource::getUrl('view', ['record' => $this->record]) => $this->record->title,
            __('filament-panels::resources/pages/edit-record.breadcrumb'),
        ];
    }
}
