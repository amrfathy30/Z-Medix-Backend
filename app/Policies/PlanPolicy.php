<?php

namespace App\Policies;

/**
 * Plans carry their own permission family, so a role can manage pricing without touching subscriptions.
 */
class PlanPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'plans';
    }
}
