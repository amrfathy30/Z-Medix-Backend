<?php

namespace App\Policies;

class BookPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'books';
    }
}
