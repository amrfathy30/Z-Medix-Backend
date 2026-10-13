<?php

namespace Tests\Feature\Fellowship;

use App\Models\FellowshipQuizSession;
use App\Models\FellowshipSessionPeriod;
use App\Services\Fellowship\FellowshipAnalyticsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Activity periods and the Study Time derived from them.
 *
 * Study Time is the sum of the periods, not the span of the session's
 * existence — a session paused overnight must not bill the night — and a period
 * crossing midnight is split across the days it covers.
 */
class FellowshipStudyTimeTest extends StudentFellowshipTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function studySession(int $questions = 2): FellowshipQuizSession
    {
        $fellowship = $this->fellowship();

        foreach (range(1, $questions) as $ignored) {
            $this->question($fellowship);
        }

        return $this->generateSession($fellowship, ['question_count' => $questions]);
    }

    public function test_generating_a_session_opens_an_activity_period(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->studySession();

        $period = $session->openPeriod();

        $this->assertNotNull($period);
        $this->assertTrue($period->isOpen());
        $this->assertSame('2026-10-12 09:00:00', $period->started_at->toDateTimeString());
    }

    public function test_pausing_closes_the_period_and_records_its_duration(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->studySession();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:10:00'));
        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

        $this->assertNull($session->refresh()->openPeriod());

        $period = $session->periods()->sole();

        $this->assertSame(600, $period->duration_seconds);
        $this->assertNull($period->open_marker);
    }

    public function test_resuming_opens_a_second_period(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->studySession();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:10:00'));
        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-12 14:00:00'));
        $this->postJson($this->sessionsUrl."/{$session->id}/resume")->assertOk();

        $this->assertSame(2, $session->refresh()->periods()->count());
        $this->assertNotNull($session->openPeriod());
    }

    public function test_only_one_period_may_be_open_at_a_time(): void
    {
        $this->actingAsStudent();
        $session = $this->studySession();

        $this->expectException(QueryException::class);

        // The table itself refuses it, not just the service above it.
        FellowshipSessionPeriod::query()->create([
            'fellowship_quiz_session_id' => $session->getKey(),
            'started_at' => now(),
            'open_marker' => FellowshipSessionPeriod::OPEN,
        ]);
    }

    public function test_many_closed_periods_may_coexist(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->studySession();

        foreach (range(1, 3) as $index) {
            Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00')->addHours($index));
            $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

            Carbon::setTestNow(Carbon::parse('2026-10-12 09:30:00')->addHours($index));
            $this->postJson($this->sessionsUrl."/{$session->id}/resume")->assertOk();
        }

        $this->assertSame(4, $session->refresh()->periods()->count());
        $this->assertSame(1, $session->periods()->open()->count());
    }

    public function test_study_time_is_the_sum_of_the_periods_not_the_session_lifetime(): void
    {
        $student = $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->studySession();

        // Ten minutes of work, then paused.
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:10:00'));
        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

        // Six hours later, five more minutes of work.
        Carbon::setTestNow(Carbon::parse('2026-10-12 15:00:00'));
        $this->postJson($this->sessionsUrl."/{$session->id}/resume")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-12 15:05:00'));
        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

        $analytics = app(FellowshipAnalyticsService::class);

        $this->assertSame(900, $analytics->sessionStudyTimeSeconds($session->refresh()));
        $this->assertSame(900, $analytics->studyTimeSeconds($student, $session->fellowship));
    }

    public function test_an_open_period_counts_up_to_the_current_request(): void
    {
        $student = $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->studySession();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:07:00'));

        $this->assertSame(
            420,
            app(FellowshipAnalyticsService::class)->studyTimeSeconds($student, $session->fellowship),
        );
    }

    public function test_a_period_spanning_midnight_is_split_across_both_days(): void
    {
        $student = $this->actingAsStudent();

        // The configured timezone decides where the day ends.
        config(['fellowship.timezone' => 'UTC']);

        Carbon::setTestNow(Carbon::parse('2026-10-12 23:40:00'));
        $session = $this->studySession();

        Carbon::setTestNow(Carbon::parse('2026-10-13 00:20:00'));
        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

        $daily = collect(app(FellowshipAnalyticsService::class)
            ->dailyStudyTime($student, $session->fellowship, days: 2))
            ->keyBy('date');

        $this->assertSame(1200, $daily['2026-10-12']['seconds'], '20 minutes before midnight.');
        $this->assertSame(1200, $daily['2026-10-13']['seconds'], '20 minutes after midnight.');
    }

    public function test_the_daily_series_covers_a_fixed_window_with_zeroes_for_quiet_days(): void
    {
        $student = $this->actingAsStudent();

        config(['fellowship.timezone' => 'UTC']);

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->studySession();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:05:00'));
        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00'));

        $daily = app(FellowshipAnalyticsService::class)
            ->dailyStudyTime($student, $session->fellowship, days: 3);

        $this->assertSame(
            [
                ['date' => '2026-10-12', 'seconds' => 300],
                ['date' => '2026-10-13', 'seconds' => 0],
                ['date' => '2026-10-14', 'seconds' => 0],
            ],
            $daily,
        );
    }

    public function test_completing_a_session_closes_its_period(): void
    {
        $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->studySession();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:03:00'));
        $this->answerAll($session);

        $session->refresh();

        $this->assertTrue($session->isCompleted());
        $this->assertNull($session->openPeriod());
        $this->assertSame(180, $session->periods()->sole()->duration_seconds);
    }

    public function test_study_time_is_scoped_to_one_fellowship(): void
    {
        $student = $this->actingAsStudent();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $first = $this->studySession();

        $other = $this->fellowship('Internal Medicine');
        $this->question($other);
        $second = $this->generateSession($other, ['question_count' => 1]);

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:10:00'));
        $this->postJson($this->sessionsUrl."/{$first->id}/pause")->assertOk();
        $this->postJson($this->sessionsUrl."/{$second->id}/pause")->assertOk();

        $analytics = app(FellowshipAnalyticsService::class);

        $this->assertSame(600, $analytics->studyTimeSeconds($student, $first->fellowship));
        $this->assertSame(600, $analytics->studyTimeSeconds($student, $other));
    }
}
