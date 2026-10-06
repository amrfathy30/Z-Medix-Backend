<?php

namespace App\Policies;

class ChapterPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'chapters';
    }
}
