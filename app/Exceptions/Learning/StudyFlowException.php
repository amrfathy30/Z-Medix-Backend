<?php

namespace App\Exceptions\Learning;

use Illuminate\Http\Response;

/**
 * Raised when the student's request is for content they may reach, but the
 * study flow does not allow the step they asked for.
 */
class StudyFlowException extends StudyException
{
    public static function studySessionAlreadyEnded(): self
    {
        return new self(
            'This study session has already ended.',
            'STUDY_SESSION_ALREADY_ENDED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function quizHasNoQuestions(): self
    {
        return new self(
            'This quiz has no questions yet.',
            'QUIZ_HAS_NO_QUESTIONS',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function attemptAlreadyCompleted(): self
    {
        return new self(
            'This quiz attempt is already completed.',
            'QUIZ_ATTEMPT_ALREADY_COMPLETED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * The chosen option is not one of those the attempt's snapshot offers for
     * this question — including an option added to the live question after the
     * attempt was created.
     */
    public static function optionNotInQuestion(): self
    {
        return new self(
            'This option is not one of the answers offered for this question.',
            'OPTION_NOT_IN_QUESTION',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function questionNotInAttempt(): self
    {
        return new self(
            'This question is not part of this quiz attempt.',
            'QUESTION_NOT_IN_ATTEMPT',
            Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * An answer is final once submitted, so a second submission is refused
     * rather than overwriting the first.
     */
    public static function questionAlreadyAnswered(): self
    {
        return new self(
            'This question has already been answered.',
            'QUESTION_ALREADY_ANSWERED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
