<?php

namespace App\Services\Learning;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\StudentChapterPageProgress;
use App\Models\StudentChapterProgress;
use App\Models\User;

/**
 * Page completion and chapter completion for one student.
 *
 * Reading progress is counted from the completion rows against the chapter's
 * live page count every time it is asked for; no percentage is stored, so
 * adding or removing a page re-weighs the chapter on its own.
 */
class ChapterProgressService
{
    public function __construct(
        private StudyAccessService $access,
        private StudyPositionService $positions,
    ) {}

    /**
     * Mark a page as read and remember it as the student's place.
     *
     * Idempotent: completing the same page twice keeps the first
     * `completed_at` rather than moving it.
     */
    public function markPageCompleted(User $student, ChapterPage $page): StudentChapterPageProgress
    {
        $this->access->assertCanAccessChapterPage($student, $page);

        /** @var StudentChapterPageProgress $progress */
        $progress = StudentChapterPageProgress::query()->firstOrCreate(
            [
                'user_id' => $student->getKey(),
                'chapter_page_id' => $page->getKey(),
            ],
            ['completed_at' => now()],
        );

        $this->positions->recordChapterPage($student, $page);

        return $progress;
    }

    public function hasCompletedPage(User $student, ChapterPage $page): bool
    {
        return StudentChapterPageProgress::query()
            ->where('user_id', $student->getKey())
            ->where('chapter_page_id', $page->getKey())
            ->exists();
    }

    /**
     * Reading progress of a chapter, e.g. 15 of 20 pages read is 75.0.
     *
     * A chapter with no pages reports 0 rather than 100: there is nothing read,
     * and chapter completion is the quiz's business, not the page count's.
     *
     * @return array{completed_pages: int, total_pages: int, percentage: float}
     */
    public function readingProgress(User $student, Chapter $chapter): array
    {
        $totalPages = $chapter->pages()->count();

        $completedPages = StudentChapterPageProgress::query()
            ->where('user_id', $student->getKey())
            ->whereIn('chapter_page_id', $chapter->pages()->select('id'))
            ->count();

        return [
            'completed_pages' => $completedPages,
            'total_pages' => $totalPages,
            'percentage' => $totalPages > 0
                ? round($completedPages / $totalPages * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Ids of the chapter's pages this student has completed, for rendering a
     * page list without a query per page.
     *
     * @return array<int, int>
     */
    public function completedPageIds(User $student, Chapter $chapter): array
    {
        return StudentChapterPageProgress::query()
            ->where('user_id', $student->getKey())
            ->whereIn('chapter_page_id', $chapter->pages()->select('id'))
            ->pluck('chapter_page_id')
            ->all();
    }

    /**
     * Record that the student finished the chapter, which is what makes the next
     * one accessible. Completing an already-completed chapter keeps the original
     * timestamp, so a retry never rewrites history.
     */
    public function markChapterCompleted(User $student, Chapter $chapter): StudentChapterProgress
    {
        /** @var StudentChapterProgress $progress */
        $progress = StudentChapterProgress::query()->firstOrCreate(
            [
                'user_id' => $student->getKey(),
                'chapter_id' => $chapter->getKey(),
            ],
            ['completed_at' => now()],
        );

        return $progress;
    }
}
