<?php

namespace App\Filament\Resources\SubscriptionRequestResource\Pages;

use App\Filament\Resources\SubscriptionRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSubscriptionRequest extends ViewRecord
{
    protected static string $resource = SubscriptionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SubscriptionRequestResource::approveAction(),
            SubscriptionRequestResource::rejectAction(),
        ];
    }
}
