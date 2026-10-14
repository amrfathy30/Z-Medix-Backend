<?php

namespace Tests\Feature\Study;

use App\Enums\QuizAttemptStatus;
use App\Models\QuizAttempt;
use Laravel\Sanctum\Sanctum;

/**
 * Finding past attempts, and reading a score off one.
 *
 * Attempts were already kept permanently with their frozen questions; what this
 * covers is the student being able to discover them, and the score and
 * percentage being reported rather than left for the client to compute.
 *
 * The history row and the attempt it links to are scored through one formula, so
 * the two can never disagree — which is what the last test here checks.
 */
class QuizAttemptHistoryListTest extends StudyTestCase
{
    public function test_a_student_lists_their_attempts_on_a_chapter_quiz(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($student = $this->student());

        // Two finished attempts, so a second one really is a second row rather
        // than a reopening of the first.
        $first = $this->startQuiz($chapter);
        $this->answerAllCorrectly($first['id']);

        $second = $this->startQuiz($chapter);
        $this->answerAllCorrectly($second['id']);

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}/quiz/attempts")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            // Newest first.
            ->assertJsonPath('data.0.id', $second['id'])
            ->assertJsonPath('data.1.id', $first['id'])
            ->assertJsonPath('data.0.status', QuizAttemptStatus::Completed->value)
            ->assertJsonPath('data.0.chapter_id', $chapter->id);

        $this->assertSame(
            2,
            QuizAttempt::query()->where('user_id', $student->id)->count(),
        );
    }

    /**
     * A history list is for choosing which attempt to review, so it carries no
     * questions: the attempt endpoint serves those.
     */
    public function test_the_history_rows_carry_scores_but_no_questions(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 4)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $questions = QuizAttempt::query()->findOrFail($attempt['id'])->questions;

        // Three right, one wrong: 75%.
        foreach ($questions as $index => $attemptQuestion) {
            $snapshot = $attemptQuestion->snapshot();
            $correctKey = (string) $attemptQuestion->correctOptionKey();

            $wrongKey = collect($snapshot->options)
                ->pluck('key')
                ->first(fn (string $key): bool => $key !== $correctKey);

            $this->answer(
                $attempt['id'],
                $attemptQuestion->id,
                $index === 3 ? $wrongKey : $correctKey,
            )->assertOk();
        }

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}/quiz/attempts")
            ->assertOk()
            ->assertJsonPath('data.0.total_questions', 4)
            ->assertJsonPath('data.0.answered_questions', 4)
            ->assertJsonPath('data.0.correct_answers', 3)
            ->assertJsonPath('data.0.incorrect_answers', 1)
            ->assertJsonPath('data.0.score', 3)
            ->assertJsonPath('data.0.percentage', 75)
            ->assertJsonPath('data.0.average_score', 75)
            ->assertJsonMissingPath('data.0.questions');
    }

    /**
     * Mid-attempt the two percentages mean different things: `percentage` is of
     * the whole attempt, `average_score` of what has been answered so far.
     *
     * The figures arrive as JSON numbers, so a whole percentage decodes as an
     * int — 25, not 25.0.
     */
    public function test_an_unfinished_attempt_reports_both_percentages(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 4)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $attemptQuestion = QuizAttempt::query()->findOrFail($attempt['id'])->questions->first();

        $this->answer($attempt['id'], $attemptQuestion->id, (string) $attemptQuestion->correctOptionKey())
            ->assertOk()
            // One of four answered, and it was right.
            ->assertJsonPath('data.attempt.percentage', 25)
            ->assertJsonPath('data.attempt.average_score', 100)
            ->assertJsonPath('data.attempt.score', 1);
    }

    /**
     * A brand-new attempt has nothing answered, so accuracy is null rather than
     * zero — zero would claim the student got everything wrong.
     */
    public function test_a_fresh_attempt_has_a_null_average_score(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);

        $this->getJson($this->studyUrl."/quiz-attempts/{$attempt['id']}")
            ->assertOk()
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.percentage', 0)
            ->assertJsonPath('data.average_score', null);
    }

    /**
     * The row in the history and the attempt it links to are the same figures.
     */
    public function test_a_history_row_agrees_with_the_attempt_it_links_to(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 3)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $this->answerAllCorrectly($attempt['id']);

        $row = $this->getJson($this->studyUrl."/chapters/{$chapter->id}/quiz/attempts")
            ->assertOk()
            ->json('data.0');

        $full = $this->getJson($this->studyUrl."/quiz-attempts/{$attempt['id']}")
            ->assertOk()
            ->json('data');

        foreach (['total_questions', 'answered_questions', 'correct_answers', 'incorrect_answers', 'score', 'percentage', 'average_score'] as $key) {
            $this->assertSame($full[$key], $row[$key], "History and attempt disagree about {$key}.");
        }
    }

    public function test_only_the_students_own_attempts_are_listed(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();
        $other = $this->student();

        Sanctum::actingAs($other);
        $this->startQuiz($chapter);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}/quiz/attempts")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_the_history_of_a_locked_chapter_is_refused(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, questions: 2);
        $locked = $subject->chapters()->get()->last();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/chapters/{$locked->id}/quiz/attempts")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAPTER_LOCKED');
    }

    public function test_the_history_is_paginated(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 1)->chapters()->first();

        Sanctum::actingAs($student = $this->student());

        foreach (range(1, 3) as $ignored) {
            $attempt = $this->startQuiz($chapter);
            $this->answerAllCorrectly($attempt['id']);
        }

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}/quiz/attempts?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_the_history_requires_authentication(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 1)->chapters()->first();

        $this->getJson($this->studyUrl."/chapters/{$chapter->id}/quiz/attempts")->assertUnauthorized();
    }
}
