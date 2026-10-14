<?php

namespace App\Policies;

/**
 * Fellowships are their own permission family, like the rest of the learning
 * content: a role can be given the Fellowship question banks without the
 * Subject tree, and the other way round.
 */
class FellowshipPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'fellowships';
    }
}
