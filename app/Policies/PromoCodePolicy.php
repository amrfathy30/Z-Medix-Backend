<?php

namespace App\Policies;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;

class PromoCodePolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'subscriptions';
    }

    public function forceDelete(Admin $admin, Model $model): bool
    {
        return false;
    }
}
