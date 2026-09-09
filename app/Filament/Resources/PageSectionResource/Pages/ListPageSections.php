<?php

namespace App\Filament\Resources\PageSectionResource\Pages;

use App\Filament\Resources\PageSectionResource;
use Filament\Resources\Pages\ListRecords;

class ListPageSections extends ListRecords
{
    protected static string $resource = PageSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
