<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\CountFellowshipQuestionsRequest;
use App\Models\Fellowship;
use App\Services\Fellowship\FellowshipQuestionPoolService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use App\Support\Api\ResolvesFellowships;
use Illuminate\Http\JsonResponse;

/**
 * How many questions a set of filters would actually yield.
 *
 * Lets the form tell the student before they commit, rather than only refusing
 * afterwards — and it is counted with exactly the query generation draws from,
 * so the two can never disagree.
 *
 * Shares generation's filter semantics but not its requirements: counting
 * names nothing and creates nothing, so it asks for no title, and
 * `question_count` is optional — a student may ask only how many questions the
 * filters hold. Given a count, the answer says whether it could be generated.
 */
class FellowshipAvailabilityController extends Controller
{
    use ApiResponse, HandlesStudyExceptions, ResolvesFellowships;

    /**
     * How many questions these filters would yield for this student.
     */
    public function __invoke(
        CountFellowshipQuestionsRequest $request,
        Fellowship $fellowship,
        FellowshipQuestionPoolService $pool,
    ): JsonResponse {
        try {
            $this->assertFellowshipAvailable($fellowship);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $available = $pool->count($fellowship, $request->user(), $request->criteria());
        $requested = $request->questionCount();

        return $this->successResponse([
            'available_questions' => $available,
            // Null rather than a figure the student never asked for.
            'requested_question_count' => $requested,
            // Without a requested count the honest question is whether a
            // session could be generated at all, which is the platform's own
            // floor on a session's size.
            'can_generate' => $available >= ($requested ?? (int) config('fellowship.session.min_question_count', 1)),
        ]);
    }
}
