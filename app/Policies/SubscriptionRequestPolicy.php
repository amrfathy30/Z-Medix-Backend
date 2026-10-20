<?php

namespace App\Policies;

/**
 * Requests, subscriptions and periods share the subscriptions family: they are one workflow (take the request, record the payment, keep the invoices).
 */
class SubscriptionRequestPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'subscriptions';
    }
}
