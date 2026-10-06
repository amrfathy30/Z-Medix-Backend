<?php

namespace App\Policies;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared authorization for the Subject learning-content tree. Mirrors
 * ContentPolicy's shape, but each entity maps to its own permission family so a
 * role can be given the learning content without the marketing website CMS.
 */
abstract class LearningContentPolicy
{
    /**
     * The permission family guarding this entity, e.g. `subjects`.
     */
    abstract protected function permissionPrefix(): string;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasPermissionTo($this->permissionPrefix().'.view');
    }

    public function view(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo($this->permissionPrefix().'.view');
    }

    public function create(Admin $admin): bool
    {
        return $admin->hasPermissionTo($this->permissionPrefix().'.create');
    }

    public function update(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo($this->permissionPrefix().'.update');
    }

    public function delete(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo($this->permissionPrefix().'.delete');
    }

    public function restore(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo($this->permissionPrefix().'.update');
    }

    public function forceDelete(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo($this->permissionPrefix().'.delete');
    }
}
