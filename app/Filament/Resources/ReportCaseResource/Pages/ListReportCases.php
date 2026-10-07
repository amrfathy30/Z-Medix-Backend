<?php

namespace App\Filament\Resources\ReportCaseResource\Pages;

use App\Filament\Resources\ReportCaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReportCases extends ListRecords
{
    protected static string $resource = ReportCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
