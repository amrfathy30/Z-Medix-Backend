<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyAccessException;
use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\QuizAttemptSummaryResource;
use App\Models\Chapter;
use App\Models\QuizAttempt;
use App\Services\Learning\QuizAttemptService;
use App\Services\Learning\StudyAccessService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A chapter quiz's attempt history for this student, newest first.
 *
 * Attempts are kept permanently, so this is how the student finds the ones they
 * can review: every attempt they have ever made on this quiz, finished or not,
 * each with its score. The attempt endpoint then serves the frozen questions
 * and answers of whichever one they pick.
 *
 * The counts come from one `withCount` over the page of results and are scored
 * through {@see QuizAttemptService::tallyFromCounts()}, so a row here reports
 * exactly what opening that attempt reports.
 */
class QuizAttemptHistoryController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(
        private StudyAccessService $access,
        private QuizAttemptService $attempts,
    ) {}

    public function __invoke(Request $request, Chapter $chapter): JsonResponse
    {
        $student = $request->user();
        $quiz = $chapter->quiz;

        try {
            if ($quiz === null) {
                throw StudyAccessException::quizUnavailable();
            }

            $this->access->assertCanAccessQuiz($student, $quiz);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $perPage = min(max((int) $request->integer('per_page', 15), 1), 50);

        $attempts = QuizAttempt::query()
            ->where('user_id', $student->getKey())
            ->where('quiz_id', $quiz->getKey())
            ->with('quiz')
            ->withCount([
                'questions as questions_total',
                'questions as questions_answered' => fn (Builder $query) => $query->whereNotNull('answered_at'),
                'questions as questions_correct' => fn (Builder $query) => $query
                    ->whereNotNull('answered_at')
                    ->where('is_correct', true),
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $attempts->getCollection()->transform(
            fn (QuizAttempt $attempt): QuizAttemptSummaryResource => new QuizAttemptSummaryResource(
                $attempt,
                $this->attempts->tallyFromCounts(
                    (int) $attempt->questions_total,
                    (int) $attempt->questions_answered,
                    (int) $attempt->questions_correct,
                ),
            ),
        );

        return $this->paginatedResponse(QuizAttemptSummaryResource::collection($attempts));
    }
}
