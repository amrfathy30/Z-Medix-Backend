<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\ChapterResource;
use App\Models\Chapter;
use App\Services\Learning\ChapterProgressService;
use App\Services\Learning\StudyAccessService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A chapter with its page list and reading progress. Access is validated on
 * every request rather than assumed from the client's navigation.
 */
class ChapterController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(
        private StudyAccessService $access,
        private ChapterProgressService $progress,
    ) {}

    public function show(Request $request, Chapter $chapter): JsonResponse
    {
        $student = $request->user();

        try {
            $this->access->assertCanAccessChapter($student, $chapter);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $chapter->load(['pages', 'quiz']);

        return $this->successResponse(new ChapterResource(
            $chapter,
            $this->progress->readingProgress($student, $chapter),
            $this->access->hasCompletedChapter($student, $chapter),
            $this->progress->completedPageIds($student, $chapter),
        ));
    }
}
