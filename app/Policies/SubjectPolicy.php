<?php

namespace App\Policies;

class SubjectPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'subjects';
    }
}
