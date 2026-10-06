<?php

namespace App\Policies;

class ChapterPagePolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'chapter_pages';
    }
}
