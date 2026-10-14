<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Fellowship\FellowshipSessionException;
use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\GenerateFellowshipSessionRequest;
use App\Http\Resources\Api\Student\FellowshipSessionResource;
use App\Http\Resources\Api\Student\FellowshipSessionSummaryResource;
use App\Models\Fellowship;
use App\Models\FellowshipQuizSession;
use App\Services\Fellowship\FellowshipAnalyticsService;
use App\Services\Fellowship\FellowshipQuestionPoolService;
use App\Services\Fellowship\FellowshipSessionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use App\Support\Api\ResolvesFellowships;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Creating sessions, listing them, and reading one back.
 *
 * A student may hold any number of sessions on one Fellowship. Generation is
 * all-or-nothing: if the filters cannot fill the requested count the response
 * says how many are available and nothing is created.
 */
class FellowshipSessionController extends Controller
{
    use ApiResponse, HandlesStudyExceptions, ResolvesFellowships;

    public function __construct(
        private FellowshipSessionService $sessions,
        private FellowshipAnalyticsService $analytics,
    ) {}

    /**
     * Session History for one Fellowship, newest first and paginated.
     */
    public function index(Request $request, Fellowship $fellowship): JsonResponse
    {
        try {
            $this->assertFellowshipAvailable($fellowship);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $perPage = min((int) $request->integer('per_page', 15), 50);

        $sessions = FellowshipQuizSession::query()
            ->where('user_id', $request->user()->getKey())
            ->where('fellowship_id', $fellowship->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $sessions->getCollection()->transform(
            fn (FellowshipQuizSession $session): FellowshipSessionSummaryResource => new FellowshipSessionSummaryResource(
                $session,
                $this->analytics->sessionTally($session),
                [
                    'study_time_seconds' => $this->analytics->sessionStudyTimeSeconds($session),
                    'topics' => $this->analytics->sessionTopics($session),
                ],
            ),
        );

        return $this->paginatedResponse(FellowshipSessionSummaryResource::collection($sessions));
    }

    /**
     * Generate a session over the Fellowship's bank.
     *
     * An insufficient pool is reported with `available_questions` so the form
     * can tell the student what it could have given them.
     */
    public function store(
        GenerateFellowshipSessionRequest $request,
        Fellowship $fellowship,
        FellowshipQuestionPoolService $pool,
    ): JsonResponse {
        $student = $request->user();
        $criteria = $request->criteria();

        try {
            $this->assertFellowshipAvailable($fellowship);

            $session = $this->sessions->generate(
                $student,
                $fellowship,
                $request->title(),
                $request->questionCount(),
                $criteria,
            );
        } catch (FellowshipSessionException $exception) {
            return $this->errorResponse(
                $exception->getMessage(),
                ['available_questions' => $pool->count($fellowship, $student, $criteria)],
                $exception->status,
                $exception->errorCode,
            );
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(
            $this->sessionPayload($session),
            'Session generated.',
            201,
        );
    }

    /**
     * One session's state, including the question the student is on.
     */
    public function show(Request $request, FellowshipQuizSession $fellowshipQuizSession): JsonResponse
    {
        try {
            $this->sessions->assertOwnedBy($request->user(), $fellowshipQuizSession);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse($this->sessionPayload($fellowshipQuizSession));
    }

    private function sessionPayload(FellowshipQuizSession $session): FellowshipSessionResource
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
