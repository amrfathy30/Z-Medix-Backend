<?php

namespace App\Filament\Resources\PageSectionItemResource\Pages;

use App\Filament\Resources\PageSectionItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPageSectionItems extends ListRecords
{
    protected static string $resource = PageSectionItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
