<?php

namespace App\Http\Controllers\Api\Student;

use App\Enums\FellowshipQuestionStatusFilter;
use App\Enums\QuizDifficulty;
use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\FellowshipFilterResource;
use App\Models\Fellowship;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use App\Support\Api\ResolvesFellowships;
use App\Support\Fellowship\FellowshipSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the Generate Session form needs to offer: this Fellowship's
 * filters and their values, the difficulty levels, and the question statuses.
 *
 * The filters are the Fellowship's own — they are per-Fellowship, so another
 * Fellowship's classifications never appear here.
 */
class FellowshipFilterController extends Controller
{
    use ApiResponse, HandlesStudyExceptions, ResolvesFellowships;

    /**
     * The filters, difficulties and question statuses a session may be
     * generated from.
     */
    public function __invoke(Request $request, Fellowship $fellowship): JsonResponse
    {
        try {
            $this->assertFellowshipAvailable($fellowship);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $filters = $fellowship->filters()->with('values')->get();

        return $this->successResponse([
            'filters' => FellowshipFilterResource::collection($filters),
            'difficulties' => array_map(
                static fn (QuizDifficulty $difficulty): string => $difficulty->value,
                QuizDifficulty::cases(),
            ),
            'question_statuses' => array_map(
                static fn (FellowshipQuestionStatusFilter $status): string => $status->value,
                FellowshipQuestionStatusFilter::cases(),
            ),
            'total_questions' => $fellowship->availableQuestionsCount(),
            // The limit a timed session would snapshot, so the form can show it
            // before the student decides whether to enable timing.
            'question_time_limit_seconds' => FellowshipSettings::questionTimeLimitSeconds(),
            'max_question_count' => (int) config('fellowship.session.max_question_count', 200),
        ]);
    }
}
