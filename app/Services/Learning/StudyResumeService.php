<?php

namespace App\Services\Learning;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Models\StudentStudyPosition;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Assembles everything the student needs to pick a subject back up.
 *
 * When a position was recorded, it is reported as-is. When none was — a student
 * opening the subject for the first time — the state falls back to the chapter
 * they may study now, so the client always has somewhere to begin;
 * `has_position` says which of the two it is receiving.
 *
 * A stale pointer is dropped rather than returned: a page or attempt whose
 * chapter is no longer reachable (unpublished, reordered behind a chapter the
 * student has not finished) is reported as null so the client cannot resume
 * into content the access check would refuse.
 */
class StudyResumeService
{
    public function __construct(
        private StudyAccessService $access,
        private StudyPositionService $positions,
    ) {}

    /**
     * @return array{
     *     subject: Subject,
     *     has_position: bool,
     *     chapter: Chapter|null,
     *     chapter_page: ChapterPage|null,
     *     quiz_attempt: QuizAttempt|null,
     *     current_question: QuizAttemptQuestion|null,
     *     last_active_at: Carbon|null,
     * }
     */
    public function stateFor(User $student, Subject $subject): array
    {
        $this->access->assertCanAccessSubject($student, $subject);

        $position = $this->positions->forSubject($student, $subject);
        $chapter = $this->accessibleChapterOf($student, $position);

        if ($chapter === null) {
            return [
                'subject' => $subject,
                'has_position' => false,
                'chapter' => $this->access->currentAccessibleChapter($student, $subject),
                'chapter_page' => null,
                'quiz_attempt' => null,
                'current_question' => null,
                'last_active_at' => null,
            ];
        }

        $attempt = $this->resumableAttempt($position);

        return [
            'subject' => $subject,
            'has_position' => true,
            'chapter' => $chapter,
            'chapter_page' => $attempt === null ? $position->chapterPage : null,
            'quiz_attempt' => $attempt,
            'current_question' => $attempt?->currentQuestion(),
            'last_active_at' => $position->updated_at,
        ];
    }

    /**
     * The position's chapter, but only while the student may still reach it.
     */
    private function accessibleChapterOf(User $student, ?StudentStudyPosition $position): ?Chapter
    {
        $chapter = $position?->chapter;

        if (! $chapter instanceof Chapter) {
            return null;
        }

        return $this->access->canAccessChapter($student, $chapter) ? $chapter : null;
    }

    /**
     * A completed attempt is not something to resume into, so only an attempt
     * still in progress is handed back.
     */
    private function resumableAttempt(StudentStudyPosition $position): ?QuizAttempt
    {
        $attempt = $position->quizAttempt;

        if (! $attempt instanceof QuizAttempt || ! $attempt->isInProgress()) {
            return null;
        }

        return $attempt;
    }
}
