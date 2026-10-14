<?php

namespace Tests\Feature\Fellowship;

use App\Models\FellowshipQuizSession;
use App\Support\Fellowship\FellowshipSettings;
use Illuminate\Support\Carbon;

/**
 * Question timing, which is advisory and nothing else.
 *
 * The claims under test: the clock runs per question and only while the session
 * is running, nothing happens when the limit expires, a late answer is still
 * graded normally, and `answered_within_time` never touches a score.
 */
class FellowshipQuestionTimingTest extends StudentFellowshipTestCase
{
    private function timedSession(int $limit = 60, int $questions = 2): FellowshipQuizSession
    {
        FellowshipSettings::setQuestionTimeLimitSeconds($limit);

        $fellowship = $this->fellowship();

        foreach (range(1, $questions) as $ignored) {
            $this->question($fellowship);
        }

        return $this->generateSession($fellowship, [
            'question_count' => $questions,
            'timer_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_answer_inside_the_limit_is_recorded_as_within_time(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->timedSession(limit: 60);

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:30'));
        $this->answerCurrent($session)
            ->assertOk()
            ->assertJsonPath('data.question.answered_within_time', true);
    }

    public function test_an_answer_after_the_limit_is_recorded_as_late_but_still_graded(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->timedSession(limit: 60);

        // Well past the limit. Nothing was submitted or advanced on our behalf.
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:05:00'));

        $this->assertFalse($session->refresh()->currentQuestion()->isAnswered());
        $this->assertSame(1, $session->current_position);

        $response = $this->answerCurrent($session, correctly: true)->assertOk();

        $response->assertJsonPath('data.question.answered_within_time', false);
        // Late, and still correct: timing takes no part in grading.
        $response->assertJsonPath('data.question.is_correct', true);
        $response->assertJsonPath('data.session.correct_answers', 1);
        $response->assertJsonPath('data.session.average_score', 100);
    }

    public function test_an_overtime_wrong_answer_is_simply_wrong(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->timedSession(limit: 30);

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:10:00'));

        $this->answerCurrent($session, correctly: false)
            ->assertOk()
            ->assertJsonPath('data.question.answered_within_time', false)
            ->assertJsonPath('data.question.is_correct', false)
            ->assertJsonPath('data.session.correct_answers', 0);
    }

    public function test_an_untimed_session_records_no_timing_verdict(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));

        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));

        $this->answerCurrent($session)
            ->assertOk()
            ->assertJsonPath('data.question.answered_within_time', null)
            ->assertJsonPath('data.question.is_correct', true);
    }

    public function test_each_question_has_its_own_clock_starting_when_it_becomes_active(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->timedSession(limit: 60, questions: 2);

        // Spend a long time on the first question.
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:04:00'));
        $this->answerCurrent($session)
            ->assertOk()
            ->assertJsonPath('data.question.answered_within_time', false);

        // The second question's clock starts now, not at generation.
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:04:20'));
        $this->answerCurrent($session)
            ->assertOk()
            ->assertJsonPath('data.question.answered_within_time', true);
    }

    public function test_pausing_stops_the_question_clock_and_resuming_continues_it(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->timedSession(limit: 60);

        // 20 seconds in, pause.
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:20'));
        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

        $question = $session->refresh()->currentQuestion();
        $this->assertSame(20, $question->active_seconds);
        $this->assertNull($question->activated_at, 'A paused question has no running stretch.');

        // An hour of real time passes while paused — none of it counts.
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));
        $this->postJson($this->sessionsUrl."/{$session->id}/resume")->assertOk();

        // 20 banked plus 30 more is 50, still inside the 60 second limit.
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:30'));
        $this->answerCurrent($session)
            ->assertOk()
            ->assertJsonPath('data.question.answered_within_time', true);
    }

    public function test_time_keeps_running_when_the_student_leaves_without_pausing(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->timedSession(limit: 60);

        // The browser was closed; no Pause was sent. The clock does not stop.
        Carbon::setTestNow(Carbon::parse('2026-10-12 11:00:00'));

        $this->answerCurrent($session)
            ->assertOk()
            ->assertJsonPath('data.question.answered_within_time', false);
    }

    public function test_an_expired_limit_changes_nothing_in_the_database_on_its_own(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->timedSession(limit: 10);

        $before = $session->refresh()->currentQuestion()->only([
            'selected_option_key', 'is_correct', 'answered_at', 'answered_within_time',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:30:00'));

        // Reading the session long after the limit expired.
        $this->getJson($this->sessionsUrl."/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.current_position', 1)
            ->assertJsonPath('data.questions.0.is_answered', false);

        $this->assertSame($before, $session->refresh()->currentQuestion()->only([
            'selected_option_key', 'is_correct', 'answered_at', 'answered_within_time',
        ]));
    }

    public function test_elapsed_time_is_reported_so_the_client_can_show_what_remains(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->timedSession(limit: 60);

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:25'));

        $this->getJson($this->sessionsUrl."/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.question_time_limit_seconds', 60)
            ->assertJsonPath('data.questions.0.elapsed_seconds', 25);
    }

    public function test_timing_never_changes_the_average_score_or_topic_performance(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->timedSession(limit: 5, questions: 2);

        // Both answers correct, both far past the limit.
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:30:00'));
        $this->answerCurrent($session)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00'));
        $this->answerCurrent($session)->assertOk();

        $this->assertSame(
            [false, false],
            $session->refresh()->questions()->pluck('answered_within_time')->map(fn ($v): bool => (bool) $v)->all(),
        );

        $this->getJson($this->fellowshipsUrl."/{$session->fellowship_id}")
            ->assertOk()
            ->assertJsonPath('data.average_score', 100)
            ->assertJsonPath('data.topic_performance.0.average_score', 100)
            ->assertJsonPath('data.topic_performance.0.correct_answers', 2);
    }
}
