<?php

namespace Tests\Feature\Study;

use App\Enums\QuizAttemptStatus;
use App\Models\Chapter;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Models\Subject;
use Laravel\Sanctum\Sanctum;

class ChapterQuizAttemptTest extends StudyTestCase
{
    public function test_starting_a_chapter_quiz_creates_an_attempt_holding_every_question(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 4)->chapters()->first();

        Sanctum::actingAs($student = $this->student());

        $response = $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")
            ->assertCreated()
            ->assertJsonPath('data.status', QuizAttemptStatus::InProgress->value)
            ->assertJsonPath('data.chapter_id', $chapter->id)
            ->assertJsonPath('data.total_questions', 4)
            ->assertJsonPath('data.answered_questions', 0)
            ->assertJsonCount(4, 'data.questions');

        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $response->json('data.id'),
            'user_id' => $student->id,
            'quiz_id' => $chapter->quiz->id,
            'status' => QuizAttemptStatus::InProgress->value,
        ]);

        $this->assertSame([1, 2, 3, 4], array_column($response->json('data.questions'), 'position'));

        // Every attempt row carries its own frozen copy of the question.
        foreach (QuizAttemptQuestion::query()->where('quiz_attempt_id', $response->json('data.id'))->get() as $row) {
            $this->assertNotEmpty($row->snapshot()->question);
            $this->assertCount(4, $row->snapshot()->options);
        }
    }

    /**
     * The student may enter the quiz without having read the chapter's pages.
     */
    public function test_a_quiz_may_be_entered_before_any_page_is_completed(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, pages: 5)->chapters()->first();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")->assertCreated();
        $this->assertDatabaseCount('student_chapter_page_progress', 0);
    }

    /**
     * Leaving mid-quiz and coming back must land on the same attempt, with its
     * questions in exactly the same order.
     */
    public function test_re_entering_a_quiz_resumes_the_same_attempt_in_the_same_order(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 6)->chapters()->first();

        Sanctum::actingAs($this->student());

        $first = $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")->assertCreated();
        $order = array_column($first->json('data.questions'), 'question_id');

        $second = $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")
            ->assertOk()
            ->assertJsonPath('message', 'Quiz attempt resumed.')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame($order, array_column($second->json('data.questions'), 'question_id'));

        $fetched = $this->getJson($this->studyUrl.'/quiz-attempts/'.$first->json('data.id'))->assertOk();
        $this->assertSame($order, array_column($fetched->json('data.questions'), 'question_id'));

        $this->assertSame(1, QuizAttempt::query()->count());
    }

    /**
     * The order is shuffled per attempt, not taken from the admin's ordering.
     * Six questions give 720 permutations, so eight attempts all matching the
     * authored order is not a realistic outcome of a working shuffle.
     */
    public function test_question_order_is_shuffled_per_attempt(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 6)->chapters()->first();
        $authoredOrder = $chapter->quiz->questions()->pluck('id')->all();

        $orders = [];

        foreach (range(1, 8) as $ignored) {
            Sanctum::actingAs($this->student());

            $orders[] = array_column(
                $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")
                    ->assertCreated()
                    ->json('data.questions'),
                'question_id',
            );
        }

        foreach ($orders as $order) {
            $this->assertEqualsCanonicalizing($authoredOrder, $order);
        }

        $this->assertNotSame(
            array_fill(0, 8, $authoredOrder),
            $orders,
            'Attempt question order was never shuffled.',
        );
    }

    public function test_an_unanswered_question_never_exposes_the_correct_answer(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $response = $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")->assertCreated();

        foreach ($response->json('data.questions') as $question) {
            $this->assertFalse($question['is_answered']);
            $this->assertNull($question['correct_option_key']);
            $this->assertNull($question['correct_option_text']);
            $this->assertNull($question['is_correct']);
            $this->assertNull($question['selected_option_key']);
            $this->assertNull($question['selected_option_text']);

            foreach ($question['options'] as $option) {
                $this->assertSame(['key', 'option_text'], array_keys($option));
            }
        }
    }

    public function test_answering_returns_the_selected_and_correct_answers(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $attemptId = $attempt['id'];
        $attemptQuestion = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);
        $question = $attemptQuestion->question;
        $wrongOptionId = (string) $this->wrongOptionId($question);

        $this->answer($attemptId, $attemptQuestion->id, $wrongOptionId)
            ->assertOk()
            ->assertJsonPath('data.question.is_answered', true)
            ->assertJsonPath('data.question.selected_option_key', $wrongOptionId)
            ->assertJsonPath('data.question.correct_option_key', (string) $this->correctOptionId($question))
            ->assertJsonPath('data.question.is_correct', false)
            ->assertJsonPath('data.attempt.answered_questions', 1)
            ->assertJsonPath('data.attempt.correct_answers', 0)
            ->assertJsonPath('data.attempt.incorrect_answers', 1)
            ->assertJsonPath('data.attempt.status', QuizAttemptStatus::InProgress->value);
    }

    public function test_an_answer_is_locked_once_submitted(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $attemptId = $attempt['id'];
        $attemptQuestion = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);
        $question = $attemptQuestion->question;
        $correctOptionId = (string) $this->correctOptionId($question);

        $this->answer($attemptId, $attemptQuestion->id, $correctOptionId)->assertOk();

        $this->answer($attemptId, $attemptQuestion->id, (string) $this->wrongOptionId($question))
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUESTION_ALREADY_ANSWERED');

        $this->assertDatabaseHas('quiz_attempt_questions', [
            'id' => $attemptQuestion->id,
            'quiz_attempt_id' => $attemptId,
            'selected_option_key' => $correctOptionId,
            'is_correct' => true,
        ]);
    }

    public function test_an_answer_requires_an_option_key(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);

        $this->postJson(
            $this->studyUrl."/quiz-attempts/{$attempt['id']}/questions/{$attempt['questions'][0]['id']}/answer",
            [],
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('option_key');

        $this->assertFalse(QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id'])->isAnswered());
    }

    /**
     * The option must be one the attempt's own snapshot offers, so an option
     * belonging to a different question is refused against the snapshot rather
     * than against the live table.
     */
    public function test_an_option_from_another_question_is_rejected(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $attemptQuestion = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);
        $other = $chapter->quiz->questions()->whereKeyNot($attemptQuestion->question_id)->firstOrFail();

        $this->answer($attempt['id'], $attemptQuestion->id, (string) $this->correctOptionId($other))
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPTION_NOT_IN_QUESTION');

        $this->assertFalse($attemptQuestion->refresh()->isAnswered());
    }

    /**
     * Two attempts of the same student, on two different quizzes: a row from one
     * cannot be answered through the other.
     */
    public function test_an_attempt_question_belonging_to_another_attempt_is_rejected(): void
    {
        $chapterA = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();
        $chapterB = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attemptA = $this->startQuiz($chapterA);
        $attemptB = $this->startQuiz($chapterB);

        $rowOfB = QuizAttemptQuestion::query()->findOrFail($attemptB['questions'][0]['id']);

        $this->answer($attemptA['id'], $rowOfB->id, (string) $rowOfB->correctOptionKey())
            ->assertNotFound()
            ->assertJsonPath('code', 'QUESTION_NOT_IN_ATTEMPT');

        $this->assertFalse($rowOfB->refresh()->isAnswered());
    }

    /**
     * Answering every question completes the attempt and the chapter, whatever
     * the score, and the next chapter becomes reachable.
     */
    public function test_answering_every_question_completes_the_attempt_and_the_chapter(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, questions: 3);
        [$first, $second] = $subject->publishedChapters()->get()->all();

        Sanctum::actingAs($student = $this->student());

        $this->getJson($this->studyUrl."/chapters/{$second->id}")->assertForbidden();

        $attempt = $this->startQuiz($first);
        $attemptId = $attempt['id'];

        // Every answer wrong: there is no pass mark, so the chapter still
        // completes.
        $lastResponse = null;

        foreach (QuizAttemptQuestion::query()->where('quiz_attempt_id', $attemptId)->get() as $row) {
            $wrongKey = collect($row->snapshot()->options)->firstWhere('is_correct', false)['key'];
            $lastResponse = $this->answer($attemptId, $row->id, $wrongKey)->assertOk();
        }

        $lastResponse
            ->assertJsonPath('data.attempt.status', QuizAttemptStatus::Completed->value)
            ->assertJsonPath('data.attempt.correct_answers', 0)
            ->assertJsonPath('data.attempt.current_question_id', null);

        $this->assertNotNull($lastResponse->json('data.attempt.completed_at'));

        $this->assertDatabaseHas('student_chapter_progress', [
            'user_id' => $student->id,
            'chapter_id' => $first->id,
        ]);

        $this->getJson($this->studyUrl."/chapters/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.is_completed', true);

        $this->getJson($this->studyUrl."/chapters/{$second->id}")->assertOk();
    }

    public function test_a_retry_after_completion_opens_a_new_attempt_and_keeps_history(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $firstAttemptId = $this->startQuiz($chapter)['id'];

        $this->answerAllCorrectly($firstAttemptId);

        $secondAttemptId = $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")
            ->assertCreated()
            ->assertJsonPath('message', 'Quiz attempt started.')
            ->assertJsonPath('data.answered_questions', 0)
            ->json('data.id');

        $this->assertNotSame($firstAttemptId, $secondAttemptId);
        $this->assertSame(2, QuizAttempt::query()->count());
        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $firstAttemptId,
            'status' => QuizAttemptStatus::Completed->value,
        ]);
    }

    public function test_a_completed_attempt_cannot_be_answered_again(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 1)->chapters()->first();
        $question = $chapter->quiz->questions()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $attemptId = $attempt['id'];
        $attemptQuestionId = $attempt['questions'][0]['id'];
        $correctKey = (string) $this->correctOptionId($question);

        $this->answer($attemptId, $attemptQuestionId, $correctKey)->assertOk();

        $this->answer($attemptId, $attemptQuestionId, $correctKey)
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUIZ_ATTEMPT_ALREADY_COMPLETED');
    }

    public function test_a_student_cannot_see_or_answer_another_students_attempt(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());
        $attempt = $this->startQuiz($chapter);
        $attemptId = $attempt['id'];
        $attemptQuestion = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/quiz-attempts/{$attemptId}")
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->answer($attemptId, $attemptQuestion->id, (string) $attemptQuestion->correctOptionKey())
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_a_quiz_with_no_questions_cannot_be_started(): void
    {
        $subject = Subject::factory()->create();
        /** @var Chapter $chapter */
        $chapter = Chapter::factory()->withQuiz()->create(['subject_id' => $subject->getKey()]);

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUIZ_HAS_NO_QUESTIONS');

        $this->assertDatabaseCount('quiz_attempts', 0);
    }

    public function test_the_attempt_state_reports_the_current_question(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 3)->chapters()->first();

        Sanctum::actingAs($this->student());

        $start = $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")->assertCreated();
        $attemptId = $start->json('data.id');
        $firstQuestion = $start->json('data.questions.0');

        $this->assertSame($firstQuestion['id'], $start->json('data.current_question_id'));

        $this->answer($attemptId, $firstQuestion['id'], $firstQuestion['options'][0]['key'])->assertOk();

        $this->getJson($this->studyUrl."/quiz-attempts/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('data.current_question_id', $start->json('data.questions.1.id'))
            ->assertJsonPath('data.answered_questions', 1);
    }
}
