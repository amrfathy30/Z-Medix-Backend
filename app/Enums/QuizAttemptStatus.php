<?php

namespace App\Enums;

/**
 * Lifecycle of a single student run through a chapter quiz. There is no failed
 * state: a quiz has no pass mark, so an attempt is either still open or done.
 */
enum QuizAttemptStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
