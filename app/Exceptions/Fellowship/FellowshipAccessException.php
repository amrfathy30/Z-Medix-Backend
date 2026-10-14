<?php

namespace App\Exceptions\Fellowship;

use App\Exceptions\Learning\StudyException;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\Response;

/**
 * Raised when a student asks for a Fellowship, a session or a session question
 * that is not theirs to see.
 *
 * Extends the study flow's exception base so the Fellowship endpoints return
 * the same error envelope — message, HTTP status and machine-readable code — as
 * the rest of the student API, through the same
 * {@see HandlesStudyExceptions} trait.
 *
 * Someone else's session is reported as not found rather than forbidden: a
 * student should not be able to learn that a session id exists.
 */
class FellowshipAccessException extends StudyException
{
    public static function notFound(): self
    {
        return new self(
            'The requested resource was not found.',
            'NOT_FOUND',
            Response::HTTP_NOT_FOUND,
        );
    }

    public static function fellowshipUnavailable(): self
    {
        return new self(
            'This fellowship is not available.',
            'FELLOWSHIP_UNAVAILABLE',
            Response::HTTP_NOT_FOUND,
        );
    }
}
