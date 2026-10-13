<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\FellowshipSessionResultResource;
use App\Models\FellowshipQuizSession;
use App\Services\Fellowship\FellowshipAnalyticsService;
use App\Services\Fellowship\FellowshipSessionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A session's result: totals, per-topic accuracy, and the ordered question
 * review with the student's answer, the correct answer, the explanation and the
 * advisory timing verdict.
 *
 * Available for a session in any state — an unfinished one simply reports what
 * has been answered so far, which is also what the history row's figures show.
 * Every figure is scoped to this session.
 */
class FellowshipSessionResultController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    /**
     * One session's totals, topic breakdown and ordered question review.
     */
    public function __invoke(
        Request $request,
        FellowshipQuizSession $fellowshipQuizSession,
        FellowshipSessionService $sessions,
        FellowshipAnalyticsService $analytics,
    ): JsonResponse {
        try {
            $sessions->assertOwnedBy($request->user(), $fellowshipQuizSession);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $fellowshipQuizSession->load('questions');

        return $this->successResponse(new FellowshipSessionResultResource(
            $fellowshipQuizSession,
            $analytics->sessionTally($fellowshipQuizSession),
            [
                'study_time_seconds' => $analytics->sessionStudyTimeSeconds($fellowshipQuizSession),
                'topics' => $analytics->sessionTopics($fellowshipQuizSession),
                'topic_performance' => $analytics->sessionTopicPerformance($fellowshipQuizSession),
            ],
        ));
    }
}
