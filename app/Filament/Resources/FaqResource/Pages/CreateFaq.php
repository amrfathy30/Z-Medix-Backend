<?php

namespace App\Filament\Resources\FaqResource\Pages;

use App\Filament\Resources\FaqResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFaq extends CreateRecord
{
    protected static string $resource = FaqResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        FaqResource::assertMayApplyStatus($data);

        $data['created_by_admin_id'] = auth('admin')->id();
        $data['updated_by_admin_id'] = auth('admin')->id();

        return $data;
    }
}
