<?php

namespace App\Enums;

enum SubscriptionRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('subscriptions.request_statuses.'.$this->value);
    }
}
