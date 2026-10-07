<?php

namespace App\Exceptions\Learning;

use Illuminate\Http\Response;

/**
 * Raised when the student asked for study content that is not theirs to read
 * right now.
 *
 * Content that is not published, and records belonging to another student, are
 * reported as "not found" rather than "forbidden": a student is never told that
 * something they cannot reach exists. A chapter the student simply has not
 * unlocked yet is a 403, because its existence is part of the subject they can
 * already see.
 */
class StudyAccessException extends StudyException
{
    public static function subjectUnavailable(): self
    {
        return new self(
            'This subject is not available.',
            'SUBJECT_UNAVAILABLE',
            Response::HTTP_NOT_FOUND,
        );
    }

    public static function chapterUnavailable(): self
    {
        return new self(
            'This chapter is not available.',
            'CHAPTER_UNAVAILABLE',
            Response::HTTP_NOT_FOUND,
        );
    }

    public static function chapterLocked(): self
    {
        return new self(
            'Complete the previous chapter to unlock this one.',
            'CHAPTER_LOCKED',
            Response::HTTP_FORBIDDEN,
        );
    }

    public static function quizUnavailable(): self
    {
        return new self(
            'This quiz is not available.',
            'QUIZ_UNAVAILABLE',
            Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * The record exists but belongs to another student.
     */
    public static function notFound(): self
    {
        return new self(
            'Not found.',
            'NOT_FOUND',
            Response::HTTP_NOT_FOUND,
        );
    }
}
