<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipSessionStatus;
use App\Models\FellowshipQuizSession;

/**
 * The session lifecycle: sequential navigation with no skipping, answers that
 * are final, and completion reached only by answering everything.
 */
class FellowshipSessionLifecycleTest extends StudentFellowshipTestCase
{
    private function sessionOfThree(): FellowshipQuizSession
    {
        $fellowship = $this->fellowship();

        foreach (range(1, 3) as $ignored) {
            $this->question($fellowship);
        }

        return $this->generateSession($fellowship, ['question_count' => 3]);
    }

    public function test_answering_the_current_question_advances_to_the_next(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $response = $this->answerCurrent($session)->assertOk();

        $response->assertJsonPath('data.question.is_answered', true);
        $response->assertJsonPath('data.session.current_position', 2);
        $response->assertJsonPath('data.session.answered_questions', 1);
        $response->assertJsonPath('data.session.status', FellowshipSessionStatus::InProgress->value);
    }

    public function test_the_answer_response_is_the_first_disclosure_of_the_correct_option(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $first = $session->questions()->first();

        // Before answering, the session state withholds correctness.
        $this->getJson($this->sessionsUrl."/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.questions.0.is_correct', null)
            ->assertJsonPath('data.questions.0.correct_option_key', null)
            ->assertJsonPath('data.questions.0.explanation', null);

        $this->answer($session, $first->getKey(), (string) $first->correctOptionKey())
            ->assertOk()
            ->assertJsonPath('data.question.is_correct', true)
            ->assertJsonPath('data.question.correct_option_key', (string) $first->correctOptionKey());
    }

    public function test_a_later_question_cannot_be_answered_before_the_current_one(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $third = $session->questions()->where('position', 3)->sole();

        $this->answer($session, $third->getKey(), (string) $third->correctOptionKey())
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_QUESTION_OUT_OF_ORDER');

        $this->assertSame(1, $session->refresh()->current_position);
        $this->assertFalse($third->refresh()->isAnswered());
    }

    public function test_an_answer_cannot_be_changed_once_confirmed(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $first = $session->questions()->first();
        $correctKey = (string) $first->correctOptionKey();
        $wrongKey = (string) collect($first->snapshot()->presentableOptions())
            ->first(fn (array $option): bool => $option['key'] !== $correctKey)['key'];

        $this->answer($session, $first->getKey(), $correctKey)->assertOk();

        $this->answer($session, $first->getKey(), $wrongKey)
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_QUESTION_ALREADY_ANSWERED');

        $this->assertSame($correctKey, $first->refresh()->selected_option_key);
        $this->assertTrue($first->is_correct);
    }

    public function test_submitting_the_same_answer_twice_is_refused_rather_than_counted_twice(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $first = $session->questions()->first();
        $key = (string) $first->correctOptionKey();

        $this->answer($session, $first->getKey(), $key)->assertOk();
        $this->answer($session, $first->getKey(), $key)->assertStatus(422);

        $this->assertSame(1, $session->refresh()->questions()->answered()->count());
        $this->assertSame(2, $session->current_position, 'The position must not advance twice.');
    }

    public function test_an_option_not_in_the_snapshot_is_refused(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $first = $session->questions()->first();

        $this->answer($session, $first->getKey(), '999999')
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_OPTION_NOT_IN_QUESTION');
    }

    public function test_an_option_added_to_the_live_question_after_generation_is_not_answerable(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $first = $session->questions()->first();

        $added = $first->question->options()->create([
            'option_text' => 'Added later',
            'is_correct' => false,
            'order' => 99,
        ]);

        $this->answer($session, $first->getKey(), (string) $added->getKey())
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_OPTION_NOT_IN_QUESTION');
    }

    public function test_a_question_from_another_session_is_refused(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();
        $other = $this->generateSession($session->fellowship, ['question_count' => 3]);

        $foreign = $other->questions()->first();

        $this->answer($session, $foreign->getKey(), (string) $foreign->correctOptionKey())
            ->assertStatus(404)
            ->assertJsonPath('code', 'FELLOWSHIP_QUESTION_NOT_IN_SESSION');
    }

    public function test_answering_the_last_question_completes_the_session(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $this->answerCurrent($session)->assertOk();
        $this->answerCurrent($session)->assertOk();

        $this->answerCurrent($session)
            ->assertOk()
            ->assertJsonPath('data.session.status', FellowshipSessionStatus::Completed->value);

        $session->refresh();

        $this->assertTrue($session->isCompleted());
        $this->assertNotNull($session->completed_at);
        $this->assertNull($session->openPeriod(), 'Completion closes the activity period.');
    }

    public function test_a_session_is_not_completed_while_a_question_is_unanswered(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $this->answerCurrent($session)->assertOk();
        $this->answerCurrent($session)->assertOk();

        $this->assertFalse($session->refresh()->isCompleted());
        $this->assertSame(FellowshipSessionStatus::InProgress, $session->status);
    }

    public function test_a_completed_session_accepts_no_further_answers(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $this->answerAll($session);

        $session->refresh();
        $last = $session->questions()->orderByDesc('position')->first();

        // The session-level failure is reported rather than the question's:
        // a completed session is closed to every question, which is the more
        // useful thing to tell the client.
        $this->answer($session, $last->getKey(), (string) $last->correctOptionKey())
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_SESSION_ALREADY_COMPLETED');
    }

    public function test_answered_questions_may_be_reviewed_without_being_modified(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $this->answerCurrent($session)->assertOk();

        $response = $this->getJson($this->sessionsUrl."/{$session->id}")->assertOk();

        $response->assertJsonPath('data.questions.0.is_answered', true);
        $response->assertJsonPath('data.questions.1.is_answered', false);
        // Reading does not move the student's place.
        $response->assertJsonPath('data.current_position', 2);
    }

    public function test_a_paused_session_cannot_be_answered_until_it_is_resumed(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

        $current = $session->refresh()->currentQuestion();

        $this->answer($session, $current->getKey(), (string) $current->correctOptionKey())
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_SESSION_NOT_IN_PROGRESS');
    }

    public function test_a_resumed_session_continues_on_the_question_it_stopped_on(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $this->answerCurrent($session)->assertOk();

        $order = $session->refresh()->questions()->pluck('fellowship_question_id')->all();

        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

        $this->postJson($this->sessionsUrl."/{$session->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.current_position', 2)
            ->assertJsonPath('data.status', FellowshipSessionStatus::InProgress->value);

        $this->assertSame(
            $order,
            $session->refresh()->questions()->pluck('fellowship_question_id')->all(),
            'Resuming must not regenerate the questions or reorder them.',
        );
    }

    public function test_pausing_twice_is_refused(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

        $this->postJson($this->sessionsUrl."/{$session->id}/pause")
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_SESSION_ALREADY_PAUSED');
    }

    public function test_resuming_a_running_session_is_refused(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $this->postJson($this->sessionsUrl."/{$session->id}/resume")
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_SESSION_ALREADY_IN_PROGRESS');
    }

    public function test_a_completed_session_cannot_be_paused_or_resumed(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        $this->answerAll($session);

        $this->postJson($this->sessionsUrl."/{$session->id}/pause")
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_SESSION_ALREADY_COMPLETED');

        $this->postJson($this->sessionsUrl."/{$session->id}/resume")
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_SESSION_ALREADY_COMPLETED');
    }

    public function test_a_student_cannot_reach_another_students_session(): void
    {
        $owner = $this->actingAsStudent();
        $session = $this->sessionOfThree();
        $current = $session->currentQuestion();

        $this->actingAsStudent();

        $this->getJson($this->sessionsUrl."/{$session->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->answer($session, $current->getKey(), (string) $current->correctOptionKey())
            ->assertStatus(404);

        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertStatus(404);
        $this->postJson($this->sessionsUrl."/{$session->id}/resume")->assertStatus(404);
        $this->getJson($this->sessionsUrl."/{$session->id}/result")->assertStatus(404);

        $this->assertSame(0, $session->refresh()->questions()->answered()->count());
    }

    public function test_session_endpoints_require_authentication(): void
    {
        $this->actingAsStudent();
        $session = $this->sessionOfThree();

        // A fresh application instance, so no student is acting.
        $this->refreshApplication();

        $this->getJson($this->sessionsUrl."/{$session->id}")->assertUnauthorized();
        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertUnauthorized();
    }
}
