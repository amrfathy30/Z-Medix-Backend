<?php

namespace Tests\Feature\Study;

use App\Models\QuizAttempt;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\User;
use App\Services\Learning\StudySessionService;
use App\Support\Fellowship\FellowshipClock;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * The Subject results and statistics screen.
 *
 * Every figure has to come from activity the student really recorded, so each
 * test here performs the activity through the API or writes the rows the API
 * writes, and then reads the statistics back. Nothing is asserted against a
 * stored aggregate, because none is stored.
 */
class SubjectAnalyticsTest extends StudyTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * An ended session, written the way {@see StudySessionService::end()}
     * writes one: the stored duration is the span between its own timestamps.
     *
     * Built explicitly rather than through the factory's `ended()` state, which
     * derives `ended_at` from the factory's own `started_at` and would ignore an
     * override passed to `create()`.
     */
    private function endedSession(
        User $student,
        Subject $subject,
        Carbon $startedAt,
        int $seconds,
    ): StudySession {
        return StudySession::factory()->for($student)->create([
            'subject_id' => $subject->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addSeconds($seconds),
            'duration_seconds' => $seconds,
        ]);
    }

    private function statistics(Subject $subject): array
    {
        return $this->getJson($this->studyUrl."/subjects/{$subject->id}/statistics")
            ->assertOk()
            ->json('data');
    }

    public function test_a_fresh_subject_reports_zeroes_and_a_null_score(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 2, questions: 2);

        Sanctum::actingAs($this->student());

        $stats = $this->statistics($subject);

        $this->assertSame(0, $stats['overall_result']['answered_questions']);
        // Null, not zero: the student has not got anything wrong, they have
        // answered nothing.
        $this->assertNull($stats['overall_result']['average_score']);
        $this->assertSame(0, $stats['chapters']['completed_chapters']);
        $this->assertSame(2, $stats['chapters']['total_chapters']);
        $this->assertSame(0, $stats['reading_progress']['completed_pages']);
        $this->assertSame(4, $stats['reading_progress']['total_pages']);
        $this->assertSame(0, $stats['study_time_seconds']);
        $this->assertSame(0, $stats['day_streak']);
        $this->assertCount(2, $stats['quiz_performance']);
    }

    public function test_the_overall_result_counts_real_answers(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 4);
        $chapter = $subject->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($chapter);
        $questions = QuizAttempt::query()->findOrFail($attempt['id'])->questions;

        // Three right, one wrong.
        foreach ($questions as $index => $attemptQuestion) {
            $correctKey = (string) $attemptQuestion->correctOptionKey();
            $wrongKey = collect($attemptQuestion->snapshot()->options)
                ->pluck('key')
                ->first(fn (string $key): bool => $key !== $correctKey);

            $this->answer($attempt['id'], $attemptQuestion->id, $index === 3 ? $wrongKey : $correctKey)
                ->assertOk();
        }

        $stats = $this->statistics($subject);

        $this->assertSame(4, $stats['overall_result']['total_questions']);
        $this->assertSame(4, $stats['overall_result']['answered_questions']);
        $this->assertSame(3, $stats['overall_result']['correct_answers']);
        $this->assertSame(1, $stats['overall_result']['incorrect_answers']);
        $this->assertSame(75, $stats['overall_result']['average_score']);
        $this->assertSame(1, $stats['overall_result']['total_attempts']);
        $this->assertSame(1, $stats['overall_result']['completed_attempts']);

        // Finishing every question completes the chapter, with no pass mark.
        $this->assertSame(1, $stats['chapters']['completed_chapters']);
    }

    /**
     * A retry is a second set of answers, not a correction of the first, so
     * both runs count towards the average.
     */
    public function test_a_retry_counts_as_further_answers(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 2);
        $chapter = $subject->chapters()->first();

        Sanctum::actingAs($this->student());

        $first = $this->startQuiz($chapter);
        $this->answerAllCorrectly($first['id']);

        $second = $this->startQuiz($chapter);
        $this->answerAllCorrectly($second['id']);

        $stats = $this->statistics($subject);

        $this->assertSame(4, $stats['overall_result']['answered_questions']);
        $this->assertSame(2, $stats['overall_result']['total_attempts']);
        $this->assertSame(100, $stats['overall_result']['average_score']);
        $this->assertSame(2, $stats['quiz_performance'][0]['total_attempts']);
    }

    public function test_reading_progress_follows_pages_marked_read(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 2, questions: 1);
        $pages = $subject->chapters()->first()->pages()->get();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/chapter-pages/{$pages->first()->id}/complete")->assertOk();

        $stats = $this->statistics($subject);

        $this->assertSame(1, $stats['reading_progress']['completed_pages']);
        $this->assertSame(4, $stats['reading_progress']['total_pages']);
        $this->assertSame(25, $stats['reading_progress']['percentage']);
    }

    public function test_study_time_sums_ended_sessions_and_reports_hours(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 1);

        Sanctum::actingAs($student = $this->student());

        $this->endedSession($student, $subject, now()->subHours(3), 1800);
        $this->endedSession($student, $subject, now()->subHours(2), 900);

        $stats = $this->statistics($subject);

        $this->assertSame(2700, $stats['study_time_seconds']);
        $this->assertSame(0.75, $stats['study_time_hours']);
    }

    /**
     * A session the student has left open counts up to the moment it is read:
     * nothing closes a session for them, so the figure has to keep moving.
     */
    public function test_an_open_session_counts_up_to_now(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 1);

        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00'));

        Sanctum::actingAs($student = $this->student());

        StudySession::factory()->for($student)->create(['subject_id' => $subject->id]);

        Carbon::setTestNow(Carbon::parse('2026-10-14 09:20:00'));

        $this->assertSame(1200, $this->statistics($subject)['study_time_seconds']);
    }

    public function test_another_students_sessions_are_not_counted(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 1);
        $other = $this->student();

        $this->endedSession($other, $subject, now()->subHour(), 3600);

        Sanctum::actingAs($this->student());

        $this->assertSame(0, $this->statistics($subject)['study_time_seconds']);
    }

    public function test_another_subjects_sessions_are_not_counted(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 1);
        $otherSubject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 1);

        Sanctum::actingAs($student = $this->student());

        $this->endedSession($student, $otherSubject, now()->subHour(), 3600);

        $this->assertSame(0, $this->statistics($subject)['study_time_seconds']);
    }

    public function test_daily_study_time_is_a_fixed_window_with_zeroes_for_quiet_days(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 1);

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00'));

        Sanctum::actingAs($student = $this->student());

        $startedAt = FellowshipClock::startOfLocalDay(now())->addHours(10);

        StudySession::factory()->for($student)->create([
            'subject_id' => $subject->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addSeconds(600),
            'duration_seconds' => 600,
        ]);

        $stats = $this->statistics($subject);
        $daily = $stats['daily_study_time'];

        $this->assertCount(7, $daily);
        $this->assertSame(FellowshipClock::dateOf(now()), $daily[6]['date']);
        $this->assertSame(600, $daily[6]['seconds']);
        $this->assertSame(0.17, $daily[6]['hours']);
        // Yesterday had nothing: a zero, not a gap.
        $this->assertSame(0, $daily[5]['seconds']);

        $this->assertCount(
            3,
            $this->getJson($this->studyUrl."/subjects/{$subject->id}/statistics?days=3")
                ->assertOk()
                ->json('data.daily_study_time'),
        );
    }

    /**
     * The total and the chart are built from the same periods, so they cannot
     * drift apart — including for a session that runs past midnight and is
     * split between two days.
     */
    public function test_the_total_equals_the_sum_of_the_daily_figures(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 1);

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00'));

        Sanctum::actingAs($student = $this->student());

        // An evening session running into the small hours of today.
        $startedAt = FellowshipClock::startOfLocalDay(now())->subHours(2);
        $this->endedSession($student, $subject, $startedAt, 4 * 3600);

        $stats = $this->statistics($subject);

        $this->assertSame(
            $stats['study_time_seconds'],
            collect($stats['daily_study_time'])->sum('seconds'),
        );
        // Two hours landed on yesterday and two on today.
        $this->assertSame(7200, $stats['daily_study_time'][5]['seconds']);
        $this->assertSame(7200, $stats['daily_study_time'][6]['seconds']);
    }

    /**
     * The streak counts days the student studied — answering a question or
     * marking a page read both count, because subject statistics cover chapter
     * learning as well as quiz activity.
     */
    public function test_the_day_streak_counts_consecutive_days_of_activity(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 3, questions: 1);
        $pages = $subject->chapters()->first()->pages()->get();

        Sanctum::actingAs($this->student());

        Carbon::setTestNow(Carbon::parse('2026-10-13 10:00:00'));
        $this->postJson($this->studyUrl."/chapter-pages/{$pages[0]->id}/complete")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));
        $this->postJson($this->studyUrl."/chapter-pages/{$pages[1]->id}/complete")->assertOk();

        $this->assertSame(2, $this->statistics($subject)['day_streak']);
    }

    public function test_a_gap_breaks_the_streak(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 3, questions: 1);
        $pages = $subject->chapters()->first()->pages()->get();

        Sanctum::actingAs($this->student());

        Carbon::setTestNow(Carbon::parse('2026-10-11 10:00:00'));
        $this->postJson($this->studyUrl."/chapter-pages/{$pages[0]->id}/complete")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));
        $this->postJson($this->studyUrl."/chapter-pages/{$pages[1]->id}/complete")->assertOk();

        $this->assertSame(1, $this->statistics($subject)['day_streak']);
    }

    public function test_quiz_performance_reports_every_chapter_and_its_scores(): void
    {
        $subject = $this->subjectWithChapters(chapters: 2, pages: 1, questions: 2);
        $first = $subject->chapters()->first();

        Sanctum::actingAs($this->student());

        $attempt = $this->startQuiz($first);
        $this->answerAllCorrectly($attempt['id']);

        $performance = $this->statistics($subject)['quiz_performance'];

        // Both chapters are reported, so the panel's shape does not change as
        // the student progresses.
        $this->assertCount(2, $performance);

        $this->assertSame($first->id, $performance[0]['chapter_id']);
        $this->assertSame($first->title, $performance[0]['chapter_title']);
        $this->assertTrue($performance[0]['is_completed']);
        $this->assertSame(1, $performance[0]['total_attempts']);
        $this->assertSame(2, $performance[0]['answered_questions']);
        $this->assertSame(100, $performance[0]['average_score']);
        $this->assertSame(100, $performance[0]['best_score']);
        $this->assertSame(100, $performance[0]['latest_score']);

        // The untouched chapter has no score rather than a zero.
        $this->assertSame(0, $performance[1]['total_attempts']);
        $this->assertNull($performance[1]['average_score']);
        $this->assertNull($performance[1]['best_score']);
        $this->assertFalse($performance[1]['is_completed']);
    }

    /**
     * Best keeps the student's highest run and latest their most recent, so a
     * worse retry does not erase what they achieved.
     */
    public function test_best_and_latest_scores_differ_across_attempts(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 2);
        $chapter = $subject->chapters()->first();

        Sanctum::actingAs($this->student());

        $first = $this->startQuiz($chapter);
        $this->answerAllCorrectly($first['id']);

        // A second run, answered wrongly throughout.
        $second = $this->startQuiz($chapter);

        foreach (QuizAttempt::query()->findOrFail($second['id'])->questions as $attemptQuestion) {
            $correctKey = (string) $attemptQuestion->correctOptionKey();
            $wrongKey = collect($attemptQuestion->snapshot()->options)
                ->pluck('key')
                ->first(fn (string $key): bool => $key !== $correctKey);

            $this->answer($second['id'], $attemptQuestion->id, $wrongKey)->assertOk();
        }

        $performance = $this->statistics($subject)['quiz_performance'][0];

        $this->assertSame(100, $performance['best_score']);
        $this->assertSame(0, $performance['latest_score']);
        $this->assertSame(50, $performance['average_score']);
    }

    public function test_another_students_answers_are_not_counted(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1, pages: 1, questions: 2);
        $chapter = $subject->chapters()->first();

        Sanctum::actingAs($other = $this->student());
        $attempt = $this->startQuiz($chapter);
        $this->answerAllCorrectly($attempt['id']);

        Sanctum::actingAs($this->student());

        $stats = $this->statistics($subject);

        $this->assertSame(0, $stats['overall_result']['answered_questions']);
        $this->assertNull($stats['overall_result']['average_score']);
        $this->assertSame(0, $stats['chapters']['completed_chapters']);
    }

    public function test_statistics_of_an_unpublished_subject_are_refused(): void
    {
        $subject = Subject::factory()->draft()->create();

        Sanctum::actingAs($this->student());

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/statistics")
            ->assertNotFound()
            ->assertJsonPath('code', 'SUBJECT_UNAVAILABLE');
    }

    public function test_statistics_require_authentication(): void
    {
        $subject = $this->subjectWithChapters(chapters: 1);

        $this->getJson($this->studyUrl."/subjects/{$subject->id}/statistics")->assertUnauthorized();
    }
}
