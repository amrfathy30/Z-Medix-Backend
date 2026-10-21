<?php

namespace App\Policies;

/**
 * A student's subscription shares the subscriptions family with its requests and periods.
 */
class StudentSubscriptionPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'subscriptions';
    }
}
