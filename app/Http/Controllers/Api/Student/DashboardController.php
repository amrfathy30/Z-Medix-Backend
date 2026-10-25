<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\DashboardActivityResource;
use App\Http\Resources\Api\Student\DashboardContinueLearningResource;
use App\Http\Resources\Api\Student\DashboardFellowshipResource;
use App\Http\Resources\Api\Student\ReportCaseResource;
use App\Http\Resources\Api\Student\SubjectListItemResource;
use App\Services\Dashboard\StudentDashboardService;
use App\Services\Subscription\FeatureAccessService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's home screen, in one read.
 *
 * Five sections, each assembled by {@see StudentDashboardService} from the
 * records the study and Fellowship modules already keep: what the student can
 * continue, the Question Banks and subjects on offer, the report cases, and
 * what they last did. Everything deeper is still addressed by its own endpoint
 * from here — the dashboard is a summary, never a second way into content.
 *
 * Student-specific data is read for the authenticated student only, and the
 * request writes nothing: no session is opened and no progress is recorded, so
 * the screen can be reloaded without changing what it reports.
 */
class DashboardController extends Controller
{
    use ApiResponse;

    /**
     * How many items each section carries. Recent Activity shows more because
     * its entries are single lines rather than cards.
     */
    private const SECTION_LIMIT = 3;

    private const ACTIVITY_LIMIT = 5;

    public function __construct(private StudentDashboardService $dashboard, private FeatureAccessService $access) {}

    public function __invoke(Request $request): JsonResponse
    {
        $student = $request->user();

        $codes = $this->access->denialCodesFor($student);
        $subjects = $codes['library'] === null ? $this->dashboard->subjects($student, self::SECTION_LIMIT) : [];
        $learningCodes = array_intersect_key($codes, array_flip(['library', 'question_bank']));

        return $this->successResponse([
            'continue_learning' => DashboardContinueLearningResource::collection(
                $this->dashboard->continueLearning($student, self::SECTION_LIMIT),
            ),
            'fellowships' => array_map(
                static fn (array $row): DashboardFellowshipResource => new DashboardFellowshipResource(
                    $row['fellowship'],
                    $row['stats'],
                ),
                $codes['question_bank'] === null ? $this->dashboard->fellowships(self::SECTION_LIMIT) : [],
            ),
            // The Subjects screen's own card, so the home screen and the full
            // list report a subject identically.
            'subjects' => array_map(
                static fn (array $row): SubjectListItemResource => new SubjectListItemResource(
                    $row['subject'],
                    $row['completed_chapters'],
                    $row['current_chapter'],
                ),
                $subjects,
            ),
            'report_cases' => ReportCaseResource::collection(
                $codes['case_report'] === null ? $this->dashboard->reportCases(self::SECTION_LIMIT) : collect(),
            ),
            'code' => [
                'continue_learning' => $learningCodes,
                'fellowships' => $codes['question_bank'],
                'subjects' => $codes['library'],
                'report_cases' => $codes['case_report'],
                'recent_activity' => $learningCodes,
            ],
            'recent_activity' => DashboardActivityResource::collection(
                $this->dashboard->recentActivity($student, self::ACTIVITY_LIMIT),
            ),
        ]);
    }
}
