<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\AnnotationCollectionRequest;
use App\Http\Requests\Api\Student\StoreHighlightRequest;
use App\Http\Resources\Api\Student\HighlightResource;
use App\Models\ChapterPage;
use App\Models\StudentHighlight;
use App\Services\Learning\StudyAnnotationService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Highlighting text on a study page. A student only ever sees their own
 * highlights; another student's are reported as not found.
 */
class HighlightController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(private StudyAnnotationService $annotations) {}

    public function index(Request $request, ChapterPage $chapterPage): JsonResponse
    {
        try {
            $highlights = $this->annotations->highlightsOn($request->user(), $chapterPage);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(HighlightResource::collection($highlights));
    }

    public function store(StoreHighlightRequest $request, ChapterPage $chapterPage): JsonResponse
    {
        try {
            $highlight = $this->annotations->createHighlight(
                $request->user(),
                $chapterPage,
                $request->selectedText(),
            );
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(new HighlightResource($highlight), 'Highlight created.', 201);
    }

    public function destroy(Request $request, StudentHighlight $highlight): JsonResponse
    {
        try {
            $this->annotations->deleteHighlight($request->user(), $highlight);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(null, 'Highlight deleted.');
    }

    /**
     * The student's highlights across every subject, newest first — the dedicated
     * collection screen. Optionally narrowed to one subject or chapter.
     *
     * Paginated, and listing only highlights on content the student may still
     * reach, which is the same rule the per-page listing above applies.
     */
    public function collection(AnnotationCollectionRequest $request): JsonResponse
    {
        $highlights = $this->annotations->highlightCollection(
            $request->user(),
            $request->subject(),
            $request->chapter(),
            $request->perPage(),
        );

        $highlights->getCollection()->transform(
            fn (StudentHighlight $record): HighlightResource => new HighlightResource($record, withContext: true),
        );

        return $this->paginatedResponse(HighlightResource::collection($highlights));
    }
}
