<?php

namespace App\Services\Learning;

use App\Exceptions\Learning\StudyAccessException;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Quiz;
use App\Models\StudentChapterProgress;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single place that decides what study content a student may reach.
 *
 * Nothing about access is stored. A chapter is accessible when it is the first
 * published chapter of an accessible subject, or when the published chapter
 * before it has been completed, so publishing, unpublishing or reordering
 * chapters changes what is reachable without any progress record being
 * rewritten.
 *
 * Every student study endpoint runs its request through this service before
 * returning content; navigation itself is the frontend's concern.
 */
class StudyAccessService
{
    /**
     * A subject is study content once it is published. There is no per-student
     * subject entitlement in the application yet, so publication is the only
     * access signal that exists.
     */
    public function canAccessSubject(User $student, Subject $subject): bool
    {
        return $subject->isPublished();
    }

    public function assertCanAccessSubject(User $student, Subject $subject): void
    {
        if (! $this->canAccessSubject($student, $subject)) {
            throw StudyAccessException::subjectUnavailable();
        }
    }

    public function canAccessChapter(User $student, Chapter $chapter): bool
    {
        if (! $chapter->isPublished()) {
            return false;
        }

        $subject = $chapter->subject;

        if (! $subject instanceof Subject || ! $this->canAccessSubject($student, $subject)) {
            return false;
        }

        $previous = $this->previousPublishedChapter($chapter);

        return $previous === null || $this->hasCompletedChapter($student, $previous);
    }

    /**
     * Fails with "unavailable" when the chapter is not study content at all, and
     * with "locked" only when the student has simply not reached it yet.
     */
    public function assertCanAccessChapter(User $student, Chapter $chapter): void
    {
        if (! $chapter->isPublished()) {
            throw StudyAccessException::chapterUnavailable();
        }

        $subject = $chapter->subject;

        if (! $subject instanceof Subject || ! $this->canAccessSubject($student, $subject)) {
            throw StudyAccessException::subjectUnavailable();
        }

        $previous = $this->previousPublishedChapter($chapter);

        if ($previous !== null && ! $this->hasCompletedChapter($student, $previous)) {
            throw StudyAccessException::chapterLocked();
        }
    }

    /**
     * A page is reachable exactly when its chapter is.
     */
    public function assertCanAccessChapterPage(User $student, ChapterPage $page): void
    {
        $chapter = $page->chapter;

        if (! $chapter instanceof Chapter) {
            throw StudyAccessException::chapterUnavailable();
        }

        $this->assertCanAccessChapter($student, $chapter);
    }

    /**
     * A chapter quiz is reachable as soon as its chapter is: the student may
     * enter it without having completed the chapter's pages.
     */
    public function assertCanAccessQuiz(User $student, ?Quiz $quiz): void
    {
        $chapter = $quiz?->chapter;

        if (! $chapter instanceof Chapter) {
            throw StudyAccessException::quizUnavailable();
        }

        $this->assertCanAccessChapter($student, $chapter);
    }

    public function hasCompletedChapter(User $student, Chapter $chapter): bool
    {
        return StudentChapterProgress::query()
            ->where('user_id', $student->getKey())
            ->where('chapter_id', $chapter->getKey())
            ->exists();
    }

    /**
     * The published chapter immediately before this one, or null when it is the
     * subject's first. Draft and archived chapters are skipped: they are not
     * study content, so they can neither be completed nor block the sequence.
     *
     * Ordered on `order, id` to match the sequence the subject's chapters
     * relation returns, so two chapters sharing an `order` still have a stable
     * predecessor.
     */
    public function previousPublishedChapter(Chapter $chapter): ?Chapter
    {
        /** @var Chapter|null $previous */
        $previous = Chapter::query()
            ->published()
            ->where('subject_id', $chapter->subject_id)
            ->whereKeyNot($chapter->getKey())
            ->where(function ($query) use ($chapter): void {
                $query->where('order', '<', $chapter->order)
                    ->orWhere(function ($tie) use ($chapter): void {
                        $tie->where('order', $chapter->order)
                            ->where('id', '<', $chapter->getKey());
                    });
            })
            ->orderByDesc('order')
            ->orderByDesc('id')
            ->first();

        return $previous;
    }

    /**
     * The chapter a student can study right now in this subject: the first
     * published chapter they have not completed, or the last one when the whole
     * subject is done. Null when the subject has no published chapters.
     */
    public function currentAccessibleChapter(User $student, Subject $subject): ?Chapter
    {
        $completedIds = StudentChapterProgress::query()
            ->where('user_id', $student->getKey())
            ->pluck('chapter_id')
            ->all();

        /** @var Collection<int, Chapter> $chapters */
        $chapters = $subject->publishedChapters()->get();

        foreach ($chapters as $chapter) {
            if (! in_array($chapter->getKey(), $completedIds, true)) {
                return $chapter;
            }
        }

        return $chapters->last();
    }
}
