<?php

namespace App\Enums;

/**
 * Lifecycle of one student Fellowship quiz session.
 *
 * There is no "abandoned" and no Finish Early: a session the student walks away
 * from stays `in_progress` (its clock keeps running until they Pause), and it
 * only becomes `completed` once every generated question carries a confirmed
 * answer.
 */
enum FellowshipSessionStatus: string
{
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Completed = 'completed';

    public function isOpen(): bool
    {
        return $this !== self::Completed;
    }
}
