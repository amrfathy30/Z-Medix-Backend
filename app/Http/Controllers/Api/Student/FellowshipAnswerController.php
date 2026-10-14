<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\AnswerFellowshipQuestionRequest;
use App\Http\Resources\Api\Student\FellowshipSessionQuestionResource;
use App\Http\Resources\Api\Student\FellowshipSessionResource;
use App\Models\FellowshipQuizSession;
use App\Models\FellowshipSessionQuestion;
use App\Services\Fellowship\FellowshipAnalyticsService;
use App\Services\Fellowship\FellowshipSessionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;

/**
 * Submitting an answer. The answer is final, and the response is the first
 * point at which the correct option and the explanation are disclosed.
 *
 * The question is addressed by its session row rather than by the live question,
 * so a session stays answerable after the original question is deleted, and the
 * option is graded against that row's snapshot.
 *
 * Answering the question the student is on advances the session; answering the
 * last open question completes it, which the returned session state reflects.
 * Submitting twice is refused rather than overwriting the first answer, which is
 * what makes a double-clicked submit harmless.
 */
class FellowshipAnswerController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    /**
     * Submit a final answer to the question the session is on.
     */
    public function __invoke(
        AnswerFellowshipQuestionRequest $request,
        FellowshipQuizSession $fellowshipQuizSession,
        FellowshipSessionQuestion $fellowshipSessionQuestion,
        FellowshipSessionService $sessions,
        FellowshipAnalyticsService $analytics,
    ): JsonResponse {
        try {
            $answered = $sessions->answer(
                $request->user(),
                $fellowshipQuizSession,
                $fellowshipSessionQuestion,
                $request->selectedOptionKey(),
            );
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $fellowshipQuizSession->refresh()->load('questions');

        return $this->successResponse([
            'question' => new FellowshipSessionQuestionResource($answered),
            'session' => new FellowshipSessionResource(
                $fellowshipQuizSession,
                $analytics->sessionTally($fellowshipQuizSession),
                [
                    'study_time_seconds' => $analytics->sessionStudyTimeSeconds($fellowshipQuizSession),
                    'topics' => $analytics->sessionTopics($fellowshipQuizSession),
                ],
            ),
        ], 'Answer submitted.');
    }
}
