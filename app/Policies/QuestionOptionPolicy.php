<?php

namespace App\Policies;

/**
 * Question options are authored inside their question, so they are guarded by
 * the questions.* permission family rather than one of their own.
 */
class QuestionOptionPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'questions';
    }
}
