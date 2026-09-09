<?php

namespace App\Policies;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;

class ContentPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->hasPermissionTo('content.view');
    }

    public function view(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo('content.view');
    }

    public function create(Admin $admin): bool
    {
        return $admin->hasPermissionTo('content.create');
    }

    public function update(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo('content.update');
    }

    public function delete(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo('content.delete');
    }

    public function restore(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo('content.update');
    }

    public function forceDelete(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo('content.delete');
    }
}
