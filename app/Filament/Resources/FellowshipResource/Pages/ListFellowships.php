<?php

namespace App\Filament\Resources\FellowshipResource\Pages;

use App\Filament\Resources\FellowshipResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFellowships extends ListRecords
{
    protected static string $resource = FellowshipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
