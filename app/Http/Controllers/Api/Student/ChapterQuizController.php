<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyAccessException;
use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\QuizAttemptResource;
use App\Models\Chapter;
use App\Services\Learning\QuizAttemptService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entering a chapter quiz. An attempt left in progress is resumed with its
 * original question order; otherwise a new attempt is opened.
 *
 * The student may enter without having read every page of the chapter.
 */
class ChapterQuizController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(private QuizAttemptService $attempts) {}

    public function __invoke(Request $request, Chapter $chapter): JsonResponse
    {
        $student = $request->user();
        $quiz = $chapter->quiz;

        try {
            if ($quiz === null) {
                throw StudyAccessException::quizUnavailable();
            }

            $resumed = $this->attempts->openAttemptFor($student, $quiz) !== null;
            $attempt = $this->attempts->startOrResume($student, $quiz);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $attempt->load(['questions', 'quiz']);

        return $this->successResponse(
            new QuizAttemptResource($attempt, $this->attempts->tally($attempt)),
            $resumed ? 'Quiz attempt resumed.' : 'Quiz attempt started.',
            $resumed ? 200 : 201,
        );
    }
}
