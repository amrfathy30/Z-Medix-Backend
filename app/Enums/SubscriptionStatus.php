<?php

namespace App\Enums;

/**
 * Where a student stands. Never stored: it is read off the subscription's
 * periods, so access ends exactly when the paid period does.
 */
enum SubscriptionStatus: string
{
    case None = 'none';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('subscriptions.statuses.'.$this->value);
    }
}
