<?php

namespace App\Services\Learning;

use App\Exceptions\Learning\StudyAccessException;
use App\Models\StudentPrivateBook;
use App\Models\Subject;
use App\Models\User;
use App\Services\Media\MediaUploadService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The student's own library: PDFs they uploaded, each filed under a subject.
 *
 * Ownership is the only access rule, and it is checked here rather than in the
 * controller, so no route can reach another student's book by addressing it
 * directly. A book belonging to someone else is reported as not found — the
 * same way the rest of the study flow refuses records that are not the
 * student's.
 *
 * The subject a book is filed under must be one the student can actually reach,
 * so a private book can never be parked under content that is not published.
 *
 * Nothing here reads inside the PDF. Title, author and the stored file's own
 * metadata are what a later AI/RAG source picker would list; extraction,
 * indexing and embedding are explicitly out of scope.
 */
class StudentPrivateBookService
{
    public function __construct(
        private MediaUploadService $media,
        private StudyAccessService $access,
    ) {}

    /**
     * The student's library, newest first, optionally filtered to one subject.
     *
     * The subject is eager-loaded because every row names it, and the media
     * relation because every row reports its file's name and size.
     *
     * @return LengthAwarePaginator<int, StudentPrivateBook>
     */
    public function library(User $student, ?Subject $subject = null, int $perPage = 15): LengthAwarePaginator
    {
        return StudentPrivateBook::query()
            ->forStudent($student)
            ->when($subject !== null, fn ($query) => $query->where('subject_id', $subject->getKey()))
            ->with(['subject', 'media'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Create a book and store its PDF.
     *
     * Both in one transaction: a library row whose file failed to store would
     * be a book the student cannot open, so neither is kept without the other.
     */
    public function create(User $student, Subject $subject, string $title, ?string $author, UploadedFile $pdf): StudentPrivateBook
    {
        $this->access->assertCanAccessSubject($student, $subject);

        return DB::transaction(function () use ($student, $subject, $title, $author, $pdf): StudentPrivateBook {
            /** @var StudentPrivateBook $book */
            $book = StudentPrivateBook::query()->create([
                'user_id' => $student->getKey(),
                'subject_id' => $subject->getKey(),
                'title' => $title,
                'author' => $author,
            ]);

            $this->media->replace($book, $pdf, StudentPrivateBook::PDF_COLLECTION);

            return $book->refresh();
        });
    }

    /**
     * Edit the book's own metadata: its title, its author, and which subject it
     * is filed under. The uploaded file is not replaced here — a different PDF
     * is a different book.
     *
     * @param  array{title?: string, author?: string|null, subject?: Subject}  $attributes
     */
    public function update(User $student, StudentPrivateBook $book, array $attributes): StudentPrivateBook
    {
        $this->assertOwnedBy($student, $book);

        $changes = [];

        if (array_key_exists('title', $attributes)) {
            $changes['title'] = $attributes['title'];
        }

        if (array_key_exists('author', $attributes)) {
            $changes['author'] = $attributes['author'];
        }

        if (array_key_exists('subject', $attributes) && $attributes['subject'] instanceof Subject) {
            $this->access->assertCanAccessSubject($student, $attributes['subject']);

            $changes['subject_id'] = $attributes['subject']->getKey();
        }

        if ($changes !== []) {
            $book->update($changes);
        }

        return $book->refresh();
    }

    /**
     * Delete the book and its file.
     *
     * A real delete, not a soft one: the student asked for their own file to be
     * gone, and the media library removes the stored file with the model it
     * belongs to.
     */
    public function delete(User $student, StudentPrivateBook $book): void
    {
        $this->assertOwnedBy($student, $book);

        $book->delete();
    }

    /**
     * The stored PDF, for the download route.
     *
     * Ownership is re-checked here, so the file cannot be reached by addressing
     * a book id that belongs to someone else.
     */
    public function pdfFor(User $student, StudentPrivateBook $book): Media
    {
        $this->assertOwnedBy($student, $book);

        $media = $book->pdf();

        if (! $media instanceof Media) {
            throw StudyAccessException::bookContentUnavailable();
        }

        return $media;
    }

    /**
     * Another student's book is reported as not found: a student is never told
     * that a book they cannot reach exists.
     */
    public function assertOwnedBy(User $student, StudentPrivateBook $book): void
    {
        if (! $book->isOwnedBy($student)) {
            throw StudyAccessException::notFound();
        }
    }
}
