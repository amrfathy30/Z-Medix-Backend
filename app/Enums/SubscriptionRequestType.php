<?php

namespace App\Enums;

enum SubscriptionRequestType: string
{
    case New = 'new';
    case Renewal = 'renewal';

    public function label(): string
    {
        return __('subscriptions.request_types.'.$this->value);
    }
}
