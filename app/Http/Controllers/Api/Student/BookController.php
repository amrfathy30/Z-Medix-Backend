<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyAccessException;
use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\BookListItemResource;
use App\Http\Resources\Api\Student\BookPageResource;
use App\Http\Resources\Api\Student\BookResource;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Subject;
use App\Services\Learning\StudyAccessService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading a subject's platform books.
 *
 * Books are the subject's other content type, independent of its chapters: they
 * belong to the subject directly and are not sequenced, so reading one never
 * depends on having completed a chapter. Only published books of an accessible
 * subject are ever reported.
 *
 * Read-only. Platform books are admin-authored, and nothing here touches how
 * the admin panel creates or stores them.
 */
class BookController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(private StudyAccessService $access) {}

    /**
     * The subject's published books, in the order the admin arranged them.
     */
    public function index(Request $request, Subject $subject): JsonResponse
    {
        try {
            $this->access->assertCanAccessSubject($request->user(), $subject);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $perPage = min(max((int) $request->integer('per_page', 15), 1), 50);

        $books = $subject->publishedBooks()
            ->withCount('pages')
            ->with('media')
            ->paginate($perPage);

        return $this->paginatedResponse(BookListItemResource::collection($books));
    }

    /**
     * One book: a PDF book with its file, a content book with its page count.
     */
    public function show(Request $request, Book $book): JsonResponse
    {
        try {
            $this->access->assertCanAccessBook($request->user(), $book);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(new BookResource($book));
    }

    /**
     * A content book's pages, in order and paginated.
     *
     * `position` is derived from the page's place in the listing rather than
     * counted per row, so a long book costs one query for the page of results
     * and one for the total.
     *
     * A PDF book has no pages: it is refused here rather than answered with an
     * empty list, so a client cannot mistake "wrong reader" for "empty book".
     */
    public function pages(Request $request, Book $book): JsonResponse
    {
        try {
            $this->access->assertCanAccessBook($request->user(), $book);

            if (! $book->isContent()) {
                throw StudyAccessException::bookContentUnavailable();
            }
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $perPage = min(max((int) $request->integer('per_page', 15), 1), 50);

        $pages = $book->pages()->paginate($perPage);

        $offset = ($pages->firstItem() ?? 1) - 1;

        $pages->getCollection()->transform(
            fn (BookPage $page, int $index): BookPageResource => new BookPageResource(
                $page,
                $offset + $index + 1,
            ),
        );

        return $this->paginatedResponse(BookPageResource::collection($pages));
    }
}
