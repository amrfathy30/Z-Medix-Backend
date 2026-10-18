<?php

namespace App\Enums;

use App\Services\Learning\StudyAccessService;

/**
 * How far a student has got with one piece of sequenced study content — a
 * chapter or its quiz — as the Subject screen draws it.
 *
 * Derived at request time from the student's own progress and attempt rows and
 * from {@see StudyAccessService}'s access answer. It is
 * never stored, and it decides nothing: `Locked` reports the access rule rather
 * than enforcing it, which the content endpoints still do for themselves.
 */
enum StudyProgressStatus: string
{
    case Completed = 'completed';
    case InProgress = 'in_progress';
    case Available = 'available';
    case Locked = 'locked';

    /**
     * The one place the four states are decided, so a chapter row and its quiz
     * row cannot answer the same question differently.
     */
    public static function decide(bool $isAccessible, bool $isCompleted, bool $hasStarted): self
    {
        return match (true) {
            ! $isAccessible => self::Locked,
            $isCompleted => self::Completed,
            $hasStarted => self::InProgress,
            default => self::Available,
        };
    }
}
