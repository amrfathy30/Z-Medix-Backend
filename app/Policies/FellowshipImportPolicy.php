<?php

namespace App\Policies;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;

/**
 * Import history is read with the question bank and written by whoever may add
 * questions to it — an import is a bulk question write, so it answers to the
 * same permissions rather than to a family of its own.
 *
 * Deleting history is deliberately not offered: an import record is the audit
 * trail of what entered the bank and when.
 */
class FellowshipImportPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->hasPermissionTo('fellowship_questions.view');
    }

    public function view(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo('fellowship_questions.view');
    }

    public function create(Admin $admin): bool
    {
        return $admin->hasPermissionTo('fellowship_questions.create');
    }

    public function update(Admin $admin, Model $model): bool
    {
        return $admin->hasPermissionTo('fellowship_questions.create');
    }

    public function delete(Admin $admin, Model $model): bool
    {
        return false;
    }
}
