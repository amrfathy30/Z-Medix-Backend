<?php

namespace App\Exceptions\Learning;

use RuntimeException;

/**
 * Raised when an operation would leave a quiz with no questions. The message is
 * the business-facing wording the admin UI surfaces verbatim.
 */
class LastQuestionException extends RuntimeException
{
    public static function cannotBeDeleted(): self
    {
        return new self('A quiz must contain at least one question.');
    }
}
