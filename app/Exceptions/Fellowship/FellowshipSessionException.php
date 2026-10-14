<?php

namespace App\Exceptions\Fellowship;

use App\Exceptions\Learning\StudyException;
use Illuminate\Http\Response;

/**
 * Raised when the student may reach the session but the step they asked for is
 * not one the lifecycle allows.
 *
 * Every case here is a rule the module is specified around: questions are
 * answered in order with no skipping, an answer is final, a completed session
 * is closed, and there is no Finish Early.
 */
class FellowshipSessionException extends StudyException
{
    public static function sessionAlreadyCompleted(): self
    {
        return new self(
            'This session is already completed.',
            'FELLOWSHIP_SESSION_ALREADY_COMPLETED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function sessionNotInProgress(): self
    {
        return new self(
            'This session is not in progress. Resume it before continuing.',
            'FELLOWSHIP_SESSION_NOT_IN_PROGRESS',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function sessionAlreadyPaused(): self
    {
        return new self(
            'This session is already paused.',
            'FELLOWSHIP_SESSION_ALREADY_PAUSED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function sessionAlreadyInProgress(): self
    {
        return new self(
            'This session is already in progress.',
            'FELLOWSHIP_SESSION_ALREADY_IN_PROGRESS',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function questionNotInSession(): self
    {
        return new self(
            'This question is not part of this session.',
            'FELLOWSHIP_QUESTION_NOT_IN_SESSION',
            Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * Questions are answered in the order they were generated: the student must
     * submit the one they are on before any later one becomes answerable, and
     * there is no Skip.
     */
    public static function questionOutOfOrder(): self
    {
        return new self(
            'Submit an answer to the current question before moving on.',
            'FELLOWSHIP_QUESTION_OUT_OF_ORDER',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * An answer is final once confirmed. A second submission is refused rather
     * than overwriting the first, which is also what makes a double-clicked
     * submit harmless.
     */
    public static function questionAlreadyAnswered(): self
    {
        return new self(
            'This question has already been answered.',
            'FELLOWSHIP_QUESTION_ALREADY_ANSWERED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function optionNotInQuestion(): self
    {
        return new self(
            'This option is not one of the answers offered for this question.',
            'FELLOWSHIP_OPTION_NOT_IN_QUESTION',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * The bank cannot fill the requested session. The caller reports the number
     * actually available alongside this, and no partial session is created.
     */
    public static function insufficientQuestions(int $available): self
    {
        return new self(
            'There are not enough questions matching these filters.',
            'FELLOWSHIP_INSUFFICIENT_QUESTIONS',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
