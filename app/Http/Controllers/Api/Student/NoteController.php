<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\StoreNoteRequest;
use App\Http\Requests\Api\Student\UpdateNoteRequest;
use App\Http\Resources\Api\Student\NoteResource;
use App\Models\ChapterPage;
use App\Models\StudentNote;
use App\Services\Learning\StudyAnnotationService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notes a student writes against a passage of a study page. A student only ever
 * sees their own notes; another student's are reported as not found.
 */
class NoteController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(private StudyAnnotationService $annotations) {}

    public function index(Request $request, ChapterPage $chapterPage): JsonResponse
    {
        try {
            $notes = $this->annotations->notesOn($request->user(), $chapterPage);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(NoteResource::collection($notes));
    }

    public function store(StoreNoteRequest $request, ChapterPage $chapterPage): JsonResponse
    {
        try {
            $note = $this->annotations->createNote(
                $request->user(),
                $chapterPage,
                $request->selectedText(),
                $request->content(),
            );
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(new NoteResource($note), 'Note created.', 201);
    }

    /**
     * Edits the note's own text. The passage it was written against is not
     * editable, so it is not accepted here.
     */
    public function update(UpdateNoteRequest $request, StudentNote $note): JsonResponse
    {
        try {
            $note = $this->annotations->updateNote($request->user(), $note, $request->content());
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(new NoteResource($note), 'Note updated.');
    }

    public function destroy(Request $request, StudentNote $note): JsonResponse
    {
        try {
            $this->annotations->deleteNote($request->user(), $note);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(null, 'Note deleted.');
    }
}
