<?php

namespace App\Services\Learning;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Models\StudentStudyPosition;
use App\Models\Subject;
use App\Models\User;

/**
 * Remembers where a student stopped inside a subject.
 *
 * The position is held apart from study sessions on purpose: the student's
 * place survives ending a session, and resuming does not require one to be
 * open. A student stops either on a page or inside a quiz, so recording one
 * clears the other — a position never claims both at once.
 */
class StudyPositionService
{
    public function recordChapterPage(User $student, ChapterPage $page): ?StudentStudyPosition
    {
        $chapter = $page->chapter;

        return $this->write($student, $chapter?->subject_id, [
            'chapter_id' => $chapter?->getKey(),
            'chapter_page_id' => $page->getKey(),
            'quiz_attempt_id' => null,
            'quiz_attempt_question_id' => null,
        ]);
    }

    /**
     * Point the position at the question the student is currently on inside an
     * attempt. A completed attempt has no current question, which is recorded
     * as the attempt alone.
     */
    public function recordQuizAttempt(User $student, QuizAttempt $attempt, ?QuizAttemptQuestion $question = null): ?StudentStudyPosition
    {
        $chapter = $attempt->quiz?->chapter;

        return $this->write($student, $chapter?->subject_id, [
            'chapter_id' => $chapter?->getKey(),
            'chapter_page_id' => null,
            'quiz_attempt_id' => $attempt->getKey(),
            'quiz_attempt_question_id' => $question?->getKey(),
        ]);
    }

    public function forSubject(User $student, Subject $subject): ?StudentStudyPosition
    {
        /** @var StudentStudyPosition|null $position */
        $position = $student->studyPositions()
            ->where('subject_id', $subject->getKey())
            ->first();

        return $position;
    }

    /**
     * A position belongs to a subject. Content whose subject can no longer be
     * resolved — a soft-deleted chapter reached through an old attempt — leaves
     * the previous position untouched rather than writing a dangling one.
     *
     * @param  array<string, int|null>  $attributes
     */
    private function write(User $student, ?int $subjectId, array $attributes): ?StudentStudyPosition
    {
        if ($subjectId === null) {
            return null;
        }

        /** @var StudentStudyPosition $position */
        $position = StudentStudyPosition::query()->updateOrCreate(
            [
                'user_id' => $student->getKey(),
                'subject_id' => $subjectId,
            ],
            $attributes,
        );

        return $position;
    }
}
