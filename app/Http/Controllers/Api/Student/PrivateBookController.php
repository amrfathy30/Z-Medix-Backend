<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\StorePrivateBookRequest;
use App\Http\Requests\Api\Student\UpdatePrivateBookRequest;
use App\Http\Resources\Api\Student\PrivateBookResource;
use App\Models\StudentPrivateBook;
use App\Models\Subject;
use App\Services\Learning\StudentPrivateBookService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's own library of uploaded PDFs.
 *
 * Every action is scoped to the authenticated student by
 * {@see StudentPrivateBookService}, which refuses another student's book as not
 * found. Nothing here touches platform books: a private book is the student's
 * file, not study content, and the two never share a record or a disk.
 */
class PrivateBookController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(private StudentPrivateBookService $privateBooks) {}

    /**
     * The student's library, newest first and paginated. `subject_id` narrows
     * it to one subject.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 50);
        $subjectId = $request->integer('subject_id');

        $books = $this->privateBooks->library(
            $request->user(),
            $subjectId === 0 ? null : Subject::query()->find($subjectId),
            $perPage,
        );

        return $this->paginatedResponse(PrivateBookResource::collection($books));
    }

    public function store(StorePrivateBookRequest $request): JsonResponse
    {
        try {
            $book = $this->privateBooks->create(
                $request->user(),
                $request->subject(),
                $request->title(),
                $request->author(),
                $request->file('pdf'),
            );
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(
            new PrivateBookResource($book->load(['subject', 'media'])),
            'Book uploaded.',
            201,
        );
    }

    public function show(Request $request, StudentPrivateBook $privateBook): JsonResponse
    {
        try {
            $this->privateBooks->assertOwnedBy($request->user(), $privateBook);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(new PrivateBookResource($privateBook->load(['subject', 'media'])));
    }

    /**
     * Edits title, author and the subject the book is filed under. The PDF
     * itself is not replaceable: a different file is a different book.
     */
    public function update(UpdatePrivateBookRequest $request, StudentPrivateBook $privateBook): JsonResponse
    {
        try {
            $book = $this->privateBooks->update(
                $request->user(),
                $privateBook,
                $request->changes(),
            );
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(
            new PrivateBookResource($book->load(['subject', 'media'])),
            'Book updated.',
        );
    }

    public function destroy(Request $request, StudentPrivateBook $privateBook): JsonResponse
    {
        try {
            $this->privateBooks->delete($request->user(), $privateBook);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(null, 'Book deleted.');
    }
}
