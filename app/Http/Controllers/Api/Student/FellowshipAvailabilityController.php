<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\GenerateFellowshipSessionRequest;
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
 * Shares the generation request so the filter semantics are defined once: the
 * title and count it requires are ignored here beyond validation.
 */
class FellowshipAvailabilityController extends Controller
{
    use ApiResponse, HandlesStudyExceptions, ResolvesFellowships;

    /**
     * How many questions these filters would yield for this student.
     */
    public function __invoke(
        GenerateFellowshipSessionRequest $request,
        Fellowship $fellowship,
        FellowshipQuestionPoolService $pool,
    ): JsonResponse {
        try {
            $this->assertFellowshipAvailable($fellowship);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $available = $pool->count($fellowship, $request->user(), $request->criteria());

        return $this->successResponse([
            'available_questions' => $available,
            'requested_question_count' => $request->questionCount(),
            'can_generate' => $available >= $request->questionCount(),
        ]);
    }
}
