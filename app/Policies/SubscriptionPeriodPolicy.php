<?php

namespace App\Policies;

/**
 * Periods are the invoices of a subscription and share its permission family.
 */
class SubscriptionPeriodPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'subscriptions';
    }
}
