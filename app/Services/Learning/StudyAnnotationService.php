<?php

namespace App\Services\Learning;

use App\Exceptions\Learning\StudyAccessException;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\StudentBookmark;
use App\Models\StudentHighlight;
use App\Models\StudentNote;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a student marks up while reading: highlights, notes and bookmarks.
 *
 * All three are anchored by the text the student selected, not by a position in the
 * rendered page, so the backend only ever needs to know which page was being
 * read and which words were picked out — locating them visually stays the
 * frontend's concern. Storing the passage also makes a highlight or note
 * readable on its own later, with its chapter and subject reached through the
 * page rather than copied onto the row.
 *
 * Every operation runs through {@see StudyAccessService} first, so an
 * annotation can only be made or read while the chapter it sits in is still
 * reachable; a record belonging to another student is reported as not found.
 */
class StudyAnnotationService
{
    public function __construct(private StudyAccessService $access) {}

    /**
     * This student's highlights on one page, newest first.
     *
     * @return Collection<int, StudentHighlight>
     */
    public function highlightsOn(User $student, ChapterPage $page): Collection
    {
        $this->access->assertCanAccessChapterPage($student, $page);

        return StudentHighlight::query()
            ->forStudent($student)
            ->onPage($page)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function createHighlight(User $student, ChapterPage $page, string $selectedText): StudentHighlight
    {
        $this->access->assertCanAccessChapterPage($student, $page);

        /** @var StudentHighlight $highlight */
        $highlight = StudentHighlight::query()->create([
            'user_id' => $student->getKey(),
            'chapter_page_id' => $page->getKey(),
            'selected_text' => $selectedText,
        ]);

        return $highlight;
    }

    public function deleteHighlight(User $student, StudentHighlight $highlight): void
    {
        $this->assertHighlightReachable($student, $highlight);

        $highlight->delete();
    }

    /**
     * This student's notes on one page, newest first.
     *
     * @return Collection<int, StudentNote>
     */
    public function notesOn(User $student, ChapterPage $page): Collection
    {
        $this->access->assertCanAccessChapterPage($student, $page);

        return StudentNote::query()
            ->forStudent($student)
            ->onPage($page)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function createNote(User $student, ChapterPage $page, string $selectedText, string $content): StudentNote
    {
        $this->access->assertCanAccessChapterPage($student, $page);

        /** @var StudentNote $note */
        $note = StudentNote::query()->create([
            'user_id' => $student->getKey(),
            'chapter_page_id' => $page->getKey(),
            'selected_text' => $selectedText,
            'content' => $content,
        ]);

        return $note;
    }

    /**
     * Only the student's own writing is editable. The passage the note was made
     * against is what ties it to the page, so it is never rewritten.
     */
    public function updateNote(User $student, StudentNote $note, string $content): StudentNote
    {
        $this->assertNoteReachable($student, $note);

        $note->update(['content' => $content]);

        return $note->refresh();
    }

    public function deleteNote(User $student, StudentNote $note): void
    {
        $this->assertNoteReachable($student, $note);

        $note->delete();
    }

    private function assertHighlightReachable(User $student, StudentHighlight $highlight): void
    {
        if (! $highlight->isOwnedBy($student)) {
            throw StudyAccessException::notFound();
        }

        $this->assertPageStillReachable($student, $highlight->chapterPage);
    }

    private function assertNoteReachable(User $student, StudentNote $note): void
    {
        if (! $note->isOwnedBy($student)) {
            throw StudyAccessException::notFound();
        }

        $this->assertPageStillReachable($student, $note->chapterPage);
    }

    /**
     * A chapter that has since been unpublished, or locked again by its
     * predecessor being reopened, takes its annotations with it: the study
     * access rules decide this the same way they decide reading the page.
     */
    private function assertPageStillReachable(User $student, ?ChapterPage $page): void
    {
        if (! $page instanceof ChapterPage) {
            throw StudyAccessException::chapterUnavailable();
        }

        $this->access->assertCanAccessChapterPage($student, $page);
    }

    // ── Bookmarks ─────────────────────────────────────────────────────────────
    //
    // A bookmark marks a place to come back to. It carries no writing of the
    // student's own, so unlike a note it is created and removed but never
    // edited — which is why there is no `updateBookmark()` here.

    /**
     * This student's bookmarks on one page, newest first.
     *
     * @return Collection<int, StudentBookmark>
     */
    public function bookmarksOn(User $student, ChapterPage $page): Collection
    {
        $this->access->assertCanAccessChapterPage($student, $page);

        return StudentBookmark::query()
            ->forStudent($student)
            ->onPage($page)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function createBookmark(User $student, ChapterPage $page, string $selectedText): StudentBookmark
    {
        $this->access->assertCanAccessChapterPage($student, $page);

        /** @var StudentBookmark $bookmark */
        $bookmark = StudentBookmark::query()->create([
            'user_id' => $student->getKey(),
            'chapter_page_id' => $page->getKey(),
            'selected_text' => $selectedText,
        ]);

        return $bookmark;
    }

    public function deleteBookmark(User $student, StudentBookmark $bookmark): void
    {
        if (! $bookmark->isOwnedBy($student)) {
            throw StudyAccessException::notFound();
        }

        $this->assertPageStillReachable($student, $bookmark->chapterPage);

        $bookmark->delete();
    }

    // ── Collections ───────────────────────────────────────────────────────────
    //
    // The student's dedicated Highlights & Notes area, which lists what they
    // marked up across every subject rather than on one page.

    /**
     * @return LengthAwarePaginator<int, StudentHighlight>
     */
    public function highlightCollection(User $student, ?Subject $subject = null, ?Chapter $chapter = null, int $perPage = 15): LengthAwarePaginator
    {
        return $this->paginateCollection(StudentHighlight::query(), $student, $subject, $chapter, $perPage);
    }

    /**
     * @return LengthAwarePaginator<int, StudentNote>
     */
    public function noteCollection(User $student, ?Subject $subject = null, ?Chapter $chapter = null, int $perPage = 15): LengthAwarePaginator
    {
        return $this->paginateCollection(StudentNote::query(), $student, $subject, $chapter, $perPage);
    }

    /**
     * @return LengthAwarePaginator<int, StudentBookmark>
     */
    public function bookmarkCollection(User $student, ?Subject $subject = null, ?Chapter $chapter = null, int $perPage = 15): LengthAwarePaginator
    {
        return $this->paginateCollection(StudentBookmark::query(), $student, $subject, $chapter, $perPage);
    }

    /**
     * One student's annotations across their library, newest first.
     *
     * The access rule is the same one the per-page endpoints apply, decided in
     * SQL for the whole listing instead of per row: only annotations on a
     * chapter the student may reach right now are listed, so an unpublished
     * subject, an unpublished chapter or a chapter that has become locked again
     * drops out of the collection exactly as it drops out of a page request.
     * {@see StudyAccessService::accessibleChapterIds()} settles that in two
     * queries rather than two per annotation.
     *
     * The page, its chapter and its subject are eager-loaded because the
     * collection resources render them; without that a listing of 15 rows would
     * be 45 extra queries.
     *
     * @template TModel of StudentHighlight|StudentNote|StudentBookmark
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    private function paginateCollection(
        Builder $query,
        User $student,
        ?Subject $subject,
        ?Chapter $chapter,
        int $perPage,
    ): LengthAwarePaginator {
        $chapterIds = $this->access->accessibleChapterIds($student, $subject);

        if ($chapter !== null) {
            // Narrowing to one chapter can only ever remove ids: a chapter the
            // student cannot reach leaves an empty filter, not an open one.
            $chapterIds = array_values(array_intersect($chapterIds, [$chapter->getKey()]));
        }

        return $query
            ->forStudent($student)
            ->whereHas(
                'chapterPage',
                fn (Builder $page): Builder => $page->whereIn('chapter_id', $chapterIds),
            )
            ->with(['chapterPage.chapter.subject'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
