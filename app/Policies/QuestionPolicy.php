<?php

namespace App\Policies;

class QuestionPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'questions';
    }
}
