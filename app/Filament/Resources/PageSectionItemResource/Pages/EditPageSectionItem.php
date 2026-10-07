<?php

namespace App\Filament\Resources\PageSectionItemResource\Pages;

use App\Filament\Resources\PageSectionItemResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPageSectionItem extends EditRecord
{
    protected static string $resource = PageSectionItemResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ForceDeleteAction::make(), RestoreAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        PageSectionItemResource::assertMayApplyStatus($data, $this->record);

        $data['updated_by_admin_id'] = auth('admin')->id();

        return $data;
    }
}
