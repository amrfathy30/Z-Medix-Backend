<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\FellowshipActivityResource;
use App\Http\Resources\Api\Student\FellowshipDetailsResource;
use App\Http\Resources\Api\Student\FellowshipListItemResource;
use App\Models\Fellowship;
use App\Models\FellowshipFilterValue;
use App\Services\Fellowship\FellowshipAnalyticsService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use App\Support\Api\ResolvesFellowships;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Question Bank screen and the Fellowship dashboard behind it.
 *
 * The Question Bank lists Fellowships, not questions: the student picks a bank
 * and generates sessions from it. Only active Fellowships are listed, and an
 * inactive one is not reachable by id either.
 */
class FellowshipController extends Controller
{
    use ApiResponse, HandlesStudyExceptions, ResolvesFellowships;

    public function __construct(private FellowshipAnalyticsService $analytics) {}

    /**
     * Active Fellowships with this student's standing in each.
     */
    public function index(Request $request): JsonResponse
    {
        $student = $request->user();

        $fellowships = Fellowship::query()
            ->active()
            ->with(['media', 'performanceFilter.values'])
            ->withCount('questions')
            ->orderBy('name')
            ->get();

        $items = $fellowships->map(fn (Fellowship $fellowship): FellowshipListItemResource => new FellowshipListItemResource(
            $fellowship,
            [
                'performance_topics' => $this->topicOptions($fellowship),
                'total_questions' => (int) $fellowship->questions_count,
                'average_score' => $this->analytics->averageScore($student, $fellowship),
            ],
        ));

        return $this->successResponse($items);
    }

    /**
     * One Fellowship's dashboard: totals, accuracy, streak, study time and
     * recent activity, all aggregated over the student's sessions in it.
     */
    public function show(Request $request, Fellowship $fellowship): JsonResponse
    {
        $student = $request->user();

        try {
            $this->assertFellowshipAvailable($fellowship);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $fellowship->loadMissing(['media', 'performanceFilter.values']);

        $days = min(max((int) $request->integer('days', 7), 1), 90);

        return $this->successResponse(new FellowshipDetailsResource($fellowship, [
            'performance_topics' => $this->topicOptions($fellowship),
            'total_questions' => $fellowship->availableQuestionsCount(),
            'study_time_seconds' => $this->analytics->studyTimeSeconds($student, $fellowship),
            'average_score' => $this->analytics->averageScore($student, $fellowship),
            'topics_completed' => $this->analytics->topicsCompleted($student, $fellowship),
            'day_streak' => $this->analytics->dayStreak($student, $fellowship),
            'daily_study_time' => $this->analytics->dailyStudyTime($student, $fellowship, $days),
            'quiz_performance' => $this->analytics->quizPerformance($student, $fellowship),
            'topic_performance' => $this->analytics->topicPerformance($student, $fellowship),
            'recent_activity' => FellowshipActivityResource::collection(
                $this->analytics->recentActivity($student, $fellowship)
            ),
        ]));
    }

    /**
     * The Fellowship's Performance Topic values, for card classification
     * display.
     *
     * @return list<array{id: int, value: string}>
     */
    private function topicOptions(Fellowship $fellowship): array
    {
        $filter = $fellowship->performanceFilter;

        if ($filter === null) {
            return [];
        }

        return $filter->values
            ->map(fn (FellowshipFilterValue $value): array => [
                'id' => (int) $value->getKey(),
                'value' => (string) $value->value,
            ])
            ->values()
            ->all();
    }
}
