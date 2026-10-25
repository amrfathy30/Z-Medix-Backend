<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\StartStudySessionRequest;
use App\Http\Resources\Api\Student\StudySessionResource;
use App\Models\StudySession;
use App\Services\Learning\StudySessionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Study time tracking with same-subject resume and automatic subject switching.
 */
class StudySessionController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(private StudySessionService $sessions) {}

    public function store(StartStudySessionRequest $request): JsonResponse
    {
        try {
            $session = $this->sessions->start($request->user(), $request->subject());
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(new StudySessionResource($session), 'Study session started.', $session->wasRecentlyCreated ? 201 : 200);
    }

    public function end(Request $request, StudySession $studySession): JsonResponse
    {
        try {
            $session = $this->sessions->end($request->user(), $studySession);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(new StudySessionResource($session), 'Study session ended.');
    }
}
