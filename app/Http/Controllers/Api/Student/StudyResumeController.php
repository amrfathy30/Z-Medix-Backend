<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\StudyResumeResource;
use App\Models\Subject;
use App\Services\Learning\StudyResumeService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the student left off in a subject. Independent of study sessions: the
 * place is remembered whether or not a session is open.
 */
class StudyResumeController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(private StudyResumeService $resume) {}

    public function __invoke(Request $request, Subject $subject): JsonResponse
    {
        try {
            $state = $this->resume->stateFor($request->user(), $subject);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(new StudyResumeResource($state));
    }
}
