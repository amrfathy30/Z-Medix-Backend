<?php

namespace App\Policies;

/**
 * Guards a Fellowship's dynamic filters and their values. Separate from the
 * questions themselves, since renaming a classification every question is filed
 * under is a broader act than editing one question.
 */
class FellowshipFilterPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'fellowship_filters';
    }
}
