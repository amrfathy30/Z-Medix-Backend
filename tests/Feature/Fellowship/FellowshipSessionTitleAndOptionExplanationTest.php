<?php

namespace Tests\Feature\Fellowship;

use App\Models\Fellowship;
use App\Models\FellowshipQuestion;
use App\Models\FellowshipSessionQuestion;

/**
 * What a session gives away about its questions, and when.
 *
 * The title travels with the question from the moment the session is generated.
 * The per-option explanations do not: saying why an option is right names the
 * correct answer, so they are withheld exactly as `correct_option_key` is —
 * until that question has been answered, which is also what makes them part of
 * a completed session's review.
 *
 * Everything served comes from the session's own snapshot, so an admin editing
 * or deleting the question afterwards changes nothing.
 */
class FellowshipSessionTitleAndOptionExplanationTest extends StudentFellowshipTestCase
{
    /**
     * One titled question whose options each explain themselves.
     */
    private function explainedQuestion(Fellowship $fellowship): FellowshipQuestion
    {
        $question = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $question->update(['title' => 'Antiplatelet choice', 'explanation' => 'Question-level reasoning.']);

        $question->options->firstWhere('is_correct', true)
            ->update(['explanation' => 'Correct: first line therapy.']);
        $question->options->firstWhere('is_correct', false)
            ->update(['explanation' => 'Incorrect: an anticoagulant.']);

        return $question->refresh()->load('options');
    }

    public function test_the_session_returns_each_questions_title_in_order(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->explainedQuestion($fellowship);
        $this->question($fellowship, text: 'Untitled one?');

        $questions = $this->generate($fellowship, ['question_count' => 2])
            ->assertCreated()
            ->json('data.questions');

        // The sidebar's order is the session's own order, and every question
        // carries a title key whether or not it has one.
        $this->assertSame([1, 2], array_column($questions, 'position'));
        $this->assertCount(2, $questions);
        $this->assertContains('Antiplatelet choice', array_column($questions, 'title'));
        $this->assertContains(null, array_column($questions, 'title'));
    }

    public function test_an_unanswered_question_reveals_no_option_explanation_and_no_correct_answer(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->explainedQuestion($fellowship);

        $payload = $this->getJson(
            $this->sessionsUrl.'/'.$this->generateSession($fellowship, ['question_count' => 1])->id,
        )->assertOk()->json('data');

        $question = $payload['questions'][0];

        $this->assertFalse($question['is_answered']);
        $this->assertNull($question['correct_option_key']);
        $this->assertNull($question['is_correct']);
        $this->assertNull($question['explanation']);
        $this->assertNull($question['selected_option_explanation']);

        foreach ($question['options'] as $option) {
            $this->assertSame(['key', 'option_text'], array_keys($option));
        }

        $body = (string) json_encode($payload);
        $this->assertStringNotContainsString('Correct: first line therapy.', $body);
        $this->assertStringNotContainsString('Incorrect: an anticoagulant.', $body);
        $this->assertStringNotContainsString('Question-level reasoning.', $body);
    }

    public function test_answering_reveals_every_option_explanation(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->explainedQuestion($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $question = $this->answerCurrent($session)->assertOk()->json('data.question');

        $this->assertTrue($question['is_answered']);
        $this->assertSame('Antiplatelet choice', $question['title']);
        $this->assertSame('Question-level reasoning.', $question['explanation']);
        $this->assertSame('Correct: first line therapy.', $question['selected_option_explanation']);

        $explanations = array_column($question['options'], 'explanation');
        $this->assertContains('Correct: first line therapy.', $explanations);
        $this->assertContains('Incorrect: an anticoagulant.', $explanations);
        $this->assertSame(1, count(array_filter(array_column($question['options'], 'is_correct'))));
    }

    public function test_the_completed_session_review_carries_the_titles_and_option_explanations(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->explainedQuestion($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerAll($session);

        $question = $this->getJson($this->sessionsUrl."/{$session->id}/result")
            ->assertOk()
            ->json('data.questions.0');

        $this->assertSame('Antiplatelet choice', $question['title']);
        $this->assertContains(
            'Correct: first line therapy.',
            array_column($question['options'], 'explanation'),
        );
    }

    /**
     * Another student's completed session is reported as not found rather than
     * forbidden, which is how the rest of this API answers for a record that is
     * not the caller's — and it means the explanations are never reachable
     * through someone else's review.
     */
    public function test_another_students_session_review_is_not_readable(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->explainedQuestion($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerAll($session);

        // A different student, asking for the same completed session.
        $this->actingAsStudent();

        $this->getJson($this->sessionsUrl."/{$session->id}/result")
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $response = $this->getJson($this->sessionsUrl."/{$session->id}")->assertNotFound();

        $this->assertStringNotContainsString(
            'Correct: first line therapy.',
            $response->getContent() ?: '',
        );
    }

    public function test_recent_activity_carries_the_captured_title(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->explainedQuestion($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerAll($session);

        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")
            ->assertOk()
            ->assertJsonPath('data.recent_activity.0.title', 'Antiplatelet choice');
    }

    public function test_editing_the_title_and_option_explanations_does_not_change_a_generated_session(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $question = $this->explainedQuestion($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        $question->update(['title' => 'Rewritten heading']);
        $question->options->each->update(['explanation' => 'Rewritten reason']);

        $this->answerAll($session);

        $served = $this->getJson($this->sessionsUrl."/{$session->id}/result")
            ->assertOk()
            ->json('data.questions.0');

        $this->assertSame('Antiplatelet choice', $served['title']);
        $this->assertSame(
            ['Correct: first line therapy.', 'Incorrect: an anticoagulant.'],
            array_column($served['options'], 'explanation'),
        );
    }

    public function test_deleting_the_question_does_not_change_a_generated_session(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $question = $this->explainedQuestion($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        $question->options()->delete();
        $question->forceDelete();

        $this->answerAll($session);

        $served = $this->getJson($this->sessionsUrl."/{$session->id}/result")
            ->assertOk()
            ->json('data.questions.0');

        $this->assertSame('Antiplatelet choice', $served['title']);
        $this->assertContains('Correct: first line therapy.', array_column($served['options'], 'explanation'));
    }

    public function test_a_session_snapshotted_before_these_fields_stays_readable_and_answerable(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship, options: 2);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        /** @var FellowshipSessionQuestion $row */
        $row = $session->questions()->sole();

        // Exactly the document shape sessions carried before titles and
        // per-option explanations existed.
        $legacy = $row->question_snapshot;
        unset($legacy['title']);
        $legacy['version'] = 1;
        $legacy['options'] = array_map(static function (array $option): array {
            unset($option['explanation']);

            return $option;
        }, $legacy['options']);
        $row->forceFill(['question_snapshot' => $legacy])->save();

        $this->getJson($this->sessionsUrl."/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.questions.0.title', null)
            ->assertJsonCount(2, 'data.questions.0.options');

        $this->answerCurrent($session->refresh())
            ->assertOk()
            ->assertJsonPath('data.question.title', null)
            ->assertJsonPath('data.question.is_correct', true)
            ->assertJsonPath('data.question.selected_option_explanation', null);
    }
}
