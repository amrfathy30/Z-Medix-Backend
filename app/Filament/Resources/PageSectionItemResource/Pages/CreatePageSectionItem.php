<?php

namespace App\Filament\Resources\PageSectionItemResource\Pages;

use App\Filament\Resources\PageSectionItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePageSectionItem extends CreateRecord
{
    protected static string $resource = PageSectionItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        PageSectionItemResource::assertMayApplyStatus($data);

        $data['created_by_admin_id'] = auth('admin')->id();
        $data['updated_by_admin_id'] = auth('admin')->id();

        return $data;
    }
}
