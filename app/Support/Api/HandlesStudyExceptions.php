<?php

namespace App\Support\Api;

use App\Exceptions\Learning\StudyException;
use Illuminate\Http\JsonResponse;

/**
 * Translates a study flow failure into the API's error envelope, carrying the
 * exception's own status and machine-readable code so clients can branch on a
 * locked chapter, a finished attempt or an answer that is already locked in.
 *
 * Requires {@see ApiResponse}.
 */
trait HandlesStudyExceptions
{
    protected function studyErrorResponse(StudyException $exception): JsonResponse
    {
        return $this->errorResponse($exception->getMessage(), [], $exception->status, $exception->errorCode);
    }
}
