<?php

namespace App\Exceptions\Learning;

use Exception;

/**
 * Base for the student study flow's business failures. Carries the HTTP status
 * and the stable machine-readable identifier the student API surfaces, so a
 * controller can translate any of them with a single catch.
 */
abstract class StudyException extends Exception
{
    final protected function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
