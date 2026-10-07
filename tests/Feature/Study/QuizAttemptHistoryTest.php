<?php

namespace Tests\Feature\Study;

use App\Models\Question;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use Laravel\Sanctum\Sanctum;

/**
 * Attempt history is durable because each attempt carries frozen copies of its
 * questions. The admin stays free to reword, re-option or delete the live
 * content; a past attempt shows and scores exactly what the student saw.
 */
class QuizAttemptHistoryTest extends StudyTestCase
{
    public function test_attempt_creation_snapshots_the_question_text_and_options(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();
        $chapter->quiz->questions()->first()->update(['question' => 'Original wording?']);

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);

        $row = QuizAttemptQuestion::query()
            ->where('quiz_attempt_id', $attempt['id'])
            ->whereNotNull('question_id')
            ->get()
            ->firstWhere('question.question', 'Original wording?');

        $snapshot = $row->snapshot();

        $this->assertSame('Original wording?', $snapshot->question);
        $this->assertCount(4, $snapshot->options);
        $this->assertNotNull($snapshot->correctOptionKey());
        $this->assertSame($row->question_id, $snapshot->questionId);

        // The live option set is reproduced faithfully, text and correctness.
        $liveOptions = $row->question->options->map(fn ($option) => [
            'key' => (string) $option->id,
            'option_text' => $option->option_text,
            'is_correct' => (bool) $option->is_correct,
            'order' => (int) $option->order,
        ])->all();

        $this->assertEqualsCanonicalizing($liveOptions, $snapshot->options);
    }

    public function test_editing_the_question_after_the_attempt_does_not_change_it(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $row = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);
        $originalText = $row->snapshot()->question;

        $row->question->update(['question' => 'Completely rewritten by an admin?']);

        $this->getJson($this->studyUrl."/quiz-attempts/{$attempt['id']}")
            ->assertOk()
            ->assertJsonPath('data.questions.0.question', $originalText)
            ->assertJsonMissing(['question' => 'Completely rewritten by an admin?']);
    }

    public function test_editing_an_option_after_the_attempt_does_not_change_it(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $row = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);
        $originalOptions = $attempt['questions'][0]['options'];

        // Reword one option and add a brand-new one.
        $row->question->options()->first()->update(['option_text' => 'Reworded later']);
        $row->question->options()->create(['option_text' => 'Added later', 'is_correct' => false, 'order' => 99]);

        $served = $this->getJson($this->studyUrl."/quiz-attempts/{$attempt['id']}")
            ->assertOk()
            ->json('data.questions.0.options');

        $this->assertSame($originalOptions, $served);
        $this->assertNotContains('Reworded later', array_column($served, 'option_text'));
        $this->assertNotContains('Added later', array_column($served, 'option_text'));
    }

    /**
     * An option added after the attempt was created is not on offer for it, so
     * it cannot be answered with.
     */
    public function test_an_option_added_after_the_attempt_cannot_be_answered_with(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $row = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);

        $added = $row->question->options()->create([
            'option_text' => 'Added later',
            'is_correct' => true,
            'order' => 99,
        ]);

        $this->answer($attempt['id'], $row->id, (string) $added->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPTION_NOT_IN_QUESTION');

        $this->assertFalse($row->refresh()->isAnswered());
    }

    /**
     * Deleting the question succeeds — nothing blocks the admin — and the
     * attempt is still fully readable, with `question_id` nulled.
     */
    public function test_deleting_the_question_does_not_destroy_or_alter_the_attempt(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 3)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $row = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);
        $expected = $attempt['questions'][0];

        $row->question->delete();

        $this->assertDatabaseMissing('questions', ['id' => $expected['question_id']]);

        $served = $this->getJson($this->studyUrl."/quiz-attempts/{$attempt['id']}")
            ->assertOk()
            ->assertJsonPath('data.total_questions', 3)
            ->json('data.questions.0');

        $this->assertNull($served['question_id']);
        $this->assertSame($expected['question'], $served['question']);
        $this->assertSame($expected['options'], $served['options']);
        $this->assertNull($row->refresh()->question_id);
    }

    /**
     * An attempt stays answerable after its question is deleted, because the
     * answer is addressed to the attempt row, not the live question.
     */
    public function test_an_attempt_remains_answerable_after_its_question_is_deleted(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 3)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $row = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);
        $correctKey = (string) $row->correctOptionKey();

        $row->question->delete();

        $this->answer($attempt['id'], $row->id, $correctKey)
            ->assertOk()
            ->assertJsonPath('data.question.is_correct', true)
            ->assertJsonPath('data.question.selected_option_key', $correctKey)
            ->assertJsonPath('data.question.correct_option_key', $correctKey);
    }

    public function test_deleting_the_original_options_does_not_destroy_or_alter_the_attempt(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $expected = $attempt['questions'][0];
        $row = QuizAttemptQuestion::query()->findOrFail($expected['id']);

        $row->question->options()->delete();

        $this->assertSame(0, $row->question->options()->count());

        $served = $this->getJson($this->studyUrl."/quiz-attempts/{$attempt['id']}")
            ->assertOk()
            ->json('data.questions.0');

        $this->assertSame($expected['options'], $served['options']);
        $this->assertSame(2, QuizAttemptQuestion::query()->where('quiz_attempt_id', $attempt['id'])->count());
    }

    /**
     * Grading reads correctness out of the snapshot. Moving the correct answer
     * on the live question after the attempt was created must not change how
     * that attempt scores.
     */
    public function test_grading_uses_the_snapshot_correct_answer_not_the_current_one(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $row = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);

        $snapshotCorrectKey = (string) $row->correctOptionKey();
        $snapshotWrongKey = collect($row->snapshot()->options)->firstWhere('is_correct', false)['key'];

        // The admin moves the correct answer to what the snapshot calls wrong.
        $row->question->options()->update(['is_correct' => false]);
        $row->question->options()->whereKey($snapshotWrongKey)->update(['is_correct' => true]);

        // Answering the snapshot's correct option is still correct...
        $this->answer($attempt['id'], $row->id, $snapshotCorrectKey)
            ->assertOk()
            ->assertJsonPath('data.question.is_correct', true)
            ->assertJsonPath('data.question.correct_option_key', $snapshotCorrectKey);

        $this->assertTrue($row->refresh()->is_correct);

        // ...and a fresh attempt, snapshotting the quiz as it stands now, grades
        // by the new answer instead.
        Sanctum::actingAs($this->student());

        $laterAttempt = $this->startQuiz($chapter);
        $laterRow = QuizAttemptQuestion::query()
            ->where('quiz_attempt_id', $laterAttempt['id'])
            ->where('question_id', $row->question_id)
            ->sole();

        $this->assertSame($snapshotWrongKey, $laterRow->correctOptionKey());

        $this->answer($laterAttempt['id'], $laterRow->id, $snapshotCorrectKey)
            ->assertOk()
            ->assertJsonPath('data.question.is_correct', false);
    }

    /**
     * The student's own answer stays readable as text, not just as a key, after
     * the option it referred to is gone.
     */
    public function test_the_selected_answer_remains_available_after_the_option_is_deleted(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $row = QuizAttemptQuestion::query()->findOrFail($attempt['questions'][0]['id']);

        $chosen = collect($row->snapshot()->options)->firstWhere('is_correct', false);
        $correctKey = (string) $row->correctOptionKey();
        $correctText = $row->correctOptionText();

        $this->answer($attempt['id'], $row->id, $chosen['key'])->assertOk();

        $row->question->options()->delete();

        $served = $this->getJson($this->studyUrl."/quiz-attempts/{$attempt['id']}")
            ->assertOk()
            ->json('data.questions.0');

        $this->assertSame($chosen['key'], $served['selected_option_key']);
        $this->assertSame($chosen['option_text'], $served['selected_option_text']);
        $this->assertSame($correctKey, $served['correct_option_key']);
        $this->assertSame($correctText, $served['correct_option_text']);
        $this->assertFalse($served['is_correct']);
    }

    /**
     * The tallies behind the stats are counted from attempt rows and their
     * stored results, so deleting the live content leaves them untouched.
     */
    public function test_a_completed_attempt_and_its_tallies_survive_deleting_every_question(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 3)->chapters()->first();

        Sanctum::actingAs($student = $this->student());

        $attemptId = $this->startQuiz($chapter)['id'];

        $rows = QuizAttemptQuestion::query()->where('quiz_attempt_id', $attemptId)->get();

        // Two right, one wrong.
        $this->answer($attemptId, $rows[0]->id, (string) $rows[0]->correctOptionKey())->assertOk();
        $this->answer($attemptId, $rows[1]->id, (string) $rows[1]->correctOptionKey())->assertOk();
        $this->answer(
            $attemptId,
            $rows[2]->id,
            collect($rows[2]->snapshot()->options)->firstWhere('is_correct', false)['key'],
        )->assertOk();

        Question::query()->where('quiz_id', $chapter->quiz->id)->delete();

        $this->assertSame(0, $chapter->quiz->questions()->count());

        $this->getJson($this->studyUrl."/quiz-attempts/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.total_questions', 3)
            ->assertJsonPath('data.answered_questions', 3)
            ->assertJsonPath('data.correct_answers', 2)
            ->assertJsonPath('data.incorrect_answers', 1)
            ->assertJsonCount(3, 'data.questions');

        $this->assertDatabaseHas('student_chapter_progress', [
            'user_id' => $student->id,
            'chapter_id' => $chapter->id,
        ]);
    }

    /**
     * A retry snapshots the quiz as it stands at that moment, not as an earlier
     * attempt saw it.
     */
    public function test_a_retry_after_completion_snapshots_the_current_quiz_content(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $firstAttemptId = $this->startQuiz($chapter)['id'];
        $this->answerAllCorrectly($firstAttemptId);

        $chapter->quiz->questions()->first()->update(['question' => 'Reworded before the retry?']);

        $retry = $this->startQuiz($chapter);

        $this->assertNotSame($firstAttemptId, $retry['id']);
        $this->assertContains('Reworded before the retry?', array_column($retry['questions'], 'question'));

        // The finished attempt still shows the original wording.
        $this->getJson($this->studyUrl."/quiz-attempts/{$firstAttemptId}")
            ->assertOk()
            ->assertJsonMissing(['question' => 'Reworded before the retry?']);
    }

    /**
     * The one cascade that remains is from the attempt to its own rows: those
     * belong to the attempt, and removing it must not reach the questions.
     */
    public function test_deleting_an_attempt_removes_only_its_own_rows(): void
    {
        $chapter = $this->subjectWithChapters(chapters: 1, questions: 2)->chapters()->first();

        Sanctum::actingAs($this->student());

        $attemptId = $this->startQuiz($chapter)['id'];

        QuizAttempt::query()->whereKey($attemptId)->delete();

        $this->assertDatabaseMissing('quiz_attempts', ['id' => $attemptId]);
        $this->assertSame(0, QuizAttemptQuestion::query()->where('quiz_attempt_id', $attemptId)->count());
        $this->assertSame(2, $chapter->quiz->questions()->count());
    }
}
