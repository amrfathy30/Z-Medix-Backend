<?php

namespace App\Filament\Resources\ReportCaseResource\Pages;

use App\Filament\Resources\ReportCaseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditReportCase extends EditRecord
{
    protected static string $resource = ReportCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReportCaseResource::toggleActiveAction(),
            DeleteAction::make(),
        ];
    }
}
