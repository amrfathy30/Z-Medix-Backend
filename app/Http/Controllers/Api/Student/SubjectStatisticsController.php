<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\SubjectStatisticsResource;
use App\Models\Subject;
use App\Services\Learning\StudyAccessService;
use App\Services\Learning\SubjectAnalyticsService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Subject results and statistics screen.
 *
 * One endpoint for the whole screen, including the subject's overall quiz
 * result: the figures are different groupings of the same activity, and
 * splitting them across endpoints is how two screens come to disagree about the
 * same number.
 *
 * `days` sets the width of the Daily Study Hours window, defaulting to a week.
 */
class SubjectStatisticsController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(
        private StudyAccessService $access,
        private SubjectAnalyticsService $analytics,
    ) {}

    public function __invoke(Request $request, Subject $subject): JsonResponse
    {
        $student = $request->user();

        try {
            $this->access->assertCanAccessSubject($student, $subject);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $days = min(max((int) $request->integer('days', 7), 1), 90);

        return $this->successResponse(new SubjectStatisticsResource(
            $subject,
            $this->analytics->statistics($student, $subject, $days),
        ));
    }
}
