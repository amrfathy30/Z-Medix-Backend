<?php

namespace App\Filament\Resources\FaqCategoryResource\Pages;

use App\Filament\Resources\FaqCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFaqCategory extends CreateRecord
{
    protected static string $resource = FaqCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        FaqCategoryResource::assertMayApplyStatus($data);

        return $data;
    }
}
