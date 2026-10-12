<?php

namespace App\Support\Smoke;

use RuntimeException;

/**
 * A smoke step that did not behave the way the application is specified to.
 *
 * Carries the step's label and, where the step was an API call, the response
 * that disagreed with it, so the run can report what actually came back instead
 * of only that something failed.
 */
class SmokeFailure extends RuntimeException
{
    public function __construct(
        public readonly string $step,
        string $detail,
        public readonly ?SmokeApiResponse $response = null,
    ) {
        parent::__construct($detail);
    }
}
