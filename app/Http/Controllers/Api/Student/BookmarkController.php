<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\AnnotationCollectionRequest;
use App\Http\Requests\Api\Student\StoreBookmarkRequest;
use App\Http\Resources\Api\Student\BookmarkResource;
use App\Models\ChapterPage;
use App\Models\StudentBookmark;
use App\Services\Learning\StudyAnnotationService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bookmarking a passage of a study page, and the student's own collection of
 * them. A student only ever sees their own bookmarks; another student's are
 * reported as not found.
 *
 * A bookmark is a place to come back to, so there is no update: the passage it
 * points at is what it is, and the student removes it rather than editing it.
 */
class BookmarkController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(private StudyAnnotationService $annotations) {}

    public function index(Request $request, ChapterPage $chapterPage): JsonResponse
    {
        try {
            $bookmarks = $this->annotations->bookmarksOn($request->user(), $chapterPage);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(BookmarkResource::collection($bookmarks));
    }

    /**
     * The student's bookmarks across every subject, newest first — the
     * dedicated collection screen. Optionally narrowed to one subject or
     * chapter.
     */
    public function collection(AnnotationCollectionRequest $request): JsonResponse
    {
        $bookmarks = $this->annotations->bookmarkCollection(
            $request->user(),
            $request->subject(),
            $request->chapter(),
            $request->perPage(),
        );

        $bookmarks->getCollection()->transform(
            fn (StudentBookmark $bookmark): BookmarkResource => new BookmarkResource($bookmark, withContext: true),
        );

        return $this->paginatedResponse(BookmarkResource::collection($bookmarks));
    }

    public function store(StoreBookmarkRequest $request, ChapterPage $chapterPage): JsonResponse
    {
        try {
            $bookmark = $this->annotations->createBookmark(
                $request->user(),
                $chapterPage,
                $request->selectedText(),
            );
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(new BookmarkResource($bookmark), 'Bookmark created.', 201);
    }

    public function destroy(Request $request, StudentBookmark $bookmark): JsonResponse
    {
        try {
            $this->annotations->deleteBookmark($request->user(), $bookmark);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(null, 'Bookmark deleted.');
    }
}
