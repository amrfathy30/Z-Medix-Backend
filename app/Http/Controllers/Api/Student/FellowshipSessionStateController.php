<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\FellowshipSessionResource;
use App\Models\FellowshipQuizSession;
use App\Services\Fellowship\FellowshipAnalyticsService;
use App\Services\Fellowship\FellowshipSessionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pause and Resume — the only two things that stop and start a session's clock.
 *
 * Pause closes the session's activity period and banks the current question's
 * elapsed time; Resume opens a new period and continues that question from the
 * time remaining. Closing the browser does neither, by design.
 *
 * There is no Finish Early here: a session completes only by being answered
 * through.
 */
class FellowshipSessionStateController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(
        private FellowshipSessionService $sessions,
        private FellowshipAnalyticsService $analytics,
    ) {}

    /**
     * Pause the session: stop its clock and bank the current question's time.
     */
    public function pause(Request $request, FellowshipQuizSession $fellowshipQuizSession): JsonResponse
    {
        try {
            $session = $this->sessions->pause($request->user(), $fellowshipQuizSession);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse($this->payload($session), 'Session paused.');
    }

    /**
     * Resume the session on the question it stopped on.
     */
    public function resume(Request $request, FellowshipQuizSession $fellowshipQuizSession): JsonResponse
    {
        try {
            $session = $this->sessions->resume($request->user(), $fellowshipQuizSession);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse($this->payload($session), 'Session resumed.');
    }

    private function payload(FellowshipQuizSession $session): FellowshipSessionResource
    {
        $session->load('questions');

        return new FellowshipSessionResource(
            $session,
            $this->analytics->sessionTally($session),
            [
                'study_time_seconds' => $this->analytics->sessionStudyTimeSeconds($session),
                'topics' => $this->analytics->sessionTopics($session),
            ],
        );
    }
}
