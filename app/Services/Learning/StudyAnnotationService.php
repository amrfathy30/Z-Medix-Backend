<?php

namespace App\Services\Learning;

use App\Exceptions\Learning\StudyAccessException;
use App\Models\ChapterPage;
use App\Models\StudentHighlight;
use App\Models\StudentNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a student marks up while reading: highlights and notes.
 *
 * Both are anchored by the text the student selected, not by a position in the
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
}
