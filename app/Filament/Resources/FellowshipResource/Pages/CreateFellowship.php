<?php

namespace App\Filament\Resources\FellowshipResource\Pages;

use App\Filament\Resources\FellowshipResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * A Fellowship exists before any of its questions do: creation asks only for
 * the name, the image and whether it is active, and the new record's View page
 * is where questions are then imported.
 */
class CreateFellowship extends CreateRecord
{
    protected static string $resource = FellowshipResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
