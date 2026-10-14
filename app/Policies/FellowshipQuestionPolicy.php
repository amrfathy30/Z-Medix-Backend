<?php

namespace App\Policies;

/**
 * Guards the Fellowship question bank, including importing into it: an import
 * writes questions, so it is gated on `fellowship_questions.create` rather than
 * on a family of its own.
 */
class FellowshipQuestionPolicy extends LearningContentPolicy
{
    protected function permissionPrefix(): string
    {
        return 'fellowship_questions';
    }
}
