<?php

namespace App\Filament\Resources\BlogResource\Pages;

use App\Filament\Resources\BlogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlog extends CreateRecord
{
    protected static string $resource = BlogResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        BlogResource::assertMayApplyStatus($data);

        $data['created_by_admin_id'] = auth('admin')->id();
        $data['updated_by_admin_id'] = auth('admin')->id();

        return $data;
    }
}
