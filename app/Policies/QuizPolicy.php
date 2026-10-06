<?php

namespace App\Policies;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;

/**
 * A quiz has no independent lifecycle: it is created with its chapter and
 * deleted or restored along with it. Admins may view and edit a quiz, but every
 * delete/restore ability is denied so no Filament action can remove a quiz on
 * its own. The chapter's model events still cascade, since model events do not
 * consult policies.
 */
class QuizPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'quizzes';
    }

    public function delete(Admin $admin, Model $model): bool
    {
        return false;
    }

    public function restore(Admin $admin, Model $model): bool
    {
        return false;
    }

    public function forceDelete(Admin $admin, Model $model): bool
    {
        return false;
    }
}
