<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\QuizAttemptResource;
use App\Models\QuizAttempt;
use App\Services\Learning\QuizAttemptService;
use App\Services\Learning\StudyAccessService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The state of one attempt: its fixed question order, the answers already
 * locked in, and the question the student is on.
 */
class QuizAttemptController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(
        private QuizAttemptService $attempts,
        private StudyAccessService $access,
    ) {}

    public function __invoke(Request $request, QuizAttempt $quizAttempt): JsonResponse
    {
        $student = $request->user();

        try {
            $this->attempts->assertOwnedBy($student, $quizAttempt);
            $this->access->assertCanAccessQuiz($student, $quizAttempt->quiz);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $quizAttempt->load(['questions', 'quiz']);

        return $this->successResponse(
            new QuizAttemptResource($quizAttempt, $this->attempts->tally($quizAttempt)),
        );
    }
}
