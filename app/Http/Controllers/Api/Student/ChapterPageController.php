<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\ChapterPageResource;
use App\Models\ChapterPage;
use App\Services\Learning\ChapterProgressService;
use App\Services\Learning\StudyAccessService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading a study page, and marking it read.
 */
class ChapterPageController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(
        private StudyAccessService $access,
        private ChapterProgressService $progress,
    ) {}

    public function show(Request $request, ChapterPage $chapterPage): JsonResponse
    {
        $student = $request->user();

        try {
            $this->access->assertCanAccessChapterPage($student, $chapterPage);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(new ChapterPageResource(
            $chapterPage,
            $this->progress->hasCompletedPage($student, $chapterPage),
        ));
    }

    /**
     * Marking a page read is idempotent: repeating it keeps the first
     * completion rather than moving it.
     */
    public function complete(Request $request, ChapterPage $chapterPage): JsonResponse
    {
        $student = $request->user();

        try {
            $this->progress->markPageCompleted($student, $chapterPage);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $chapter = $chapterPage->chapter;

        return $this->successResponse([
            'page' => new ChapterPageResource($chapterPage, true, withContent: false),
            'reading_progress' => $this->progress->readingProgress($student, $chapter),
        ], 'Page marked as completed.');
    }
}
