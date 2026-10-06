<?php

namespace App\Policies;

class BookPagePolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'book_pages';
    }
}
