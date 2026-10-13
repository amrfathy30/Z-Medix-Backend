<?php

namespace Tests\Feature\Fellowship;

use App\Enums\QuizDifficulty;
use App\Models\Fellowship;
use App\Services\Fellowship\FellowshipAnalyticsService;
use Illuminate\Support\Carbon;

/**
 * The Question Bank and Fellowship dashboard figures.
 *
 * The rules being pinned down: only confirmed answers count, they count
 * immediately — including inside an unfinished session — a question answered
 * again is a second answer, and the size of the bank is never a denominator.
 */
class FellowshipAnalyticsTest extends StudentFellowshipTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_question_bank_lists_fellowships_with_their_topics_and_counts(): void
    {
        $this->actingAsStudent();

        $fellowship = $this->fellowship('Emergency Medicine');
        $this->question($fellowship, topic: 'Cardiology');
        $this->question($fellowship, topic: 'Neurology');

        $response = $this->getJson($this->fellowshipsUrl)->assertOk();

        $response->assertJsonPath('data.0.name', 'Emergency Medicine');
        $response->assertJsonPath('data.0.total_questions', 2);
        $response->assertJsonPath('data.0.average_score', null);
        $response->assertJsonCount(2, 'data.0.performance_topics');
        $response->assertJsonPath('data.0.performance_topics.0.value', 'Cardiology');
    }

    public function test_the_question_bank_omits_inactive_fellowships(): void
    {
        $this->actingAsStudent();

        $this->fellowship('Active One');
        Fellowship::factory()->inactive()->create(['name' => 'Hidden One']);

        $this->getJson($this->fellowshipsUrl)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active One');
    }

    public function test_an_inactive_fellowship_is_not_reachable_by_id(): void
    {
        $this->actingAsStudent();

        $fellowship = Fellowship::factory()->inactive()->withPerformanceFilter()->create();

        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'FELLOWSHIP_UNAVAILABLE');
    }

    public function test_average_score_is_correct_confirmed_answers_over_all_confirmed_answers(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        foreach (range(1, 4) as $ignored) {
            $this->question($fellowship);
        }

        $session = $this->generateSession($fellowship, ['question_count' => 4]);

        // Three right, one wrong.
        $this->answerAll($session, correct: 3);

        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")
            ->assertOk()
            ->assertJsonPath('data.average_score', 75);
    }

    public function test_the_bank_size_is_never_the_denominator(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        // Twenty questions in the bank, two answered, both right.
        foreach (range(1, 20) as $ignored) {
            $this->question($fellowship);
        }

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($session);

        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")
            ->assertOk()
            ->assertJsonPath('data.total_questions', 20)
            // 2 of 2 confirmed answers, not 2 of 20 questions.
            ->assertJsonPath('data.average_score', 100);
    }

    public function test_answers_in_an_unfinished_session_count_immediately(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        foreach (range(1, 4) as $ignored) {
            $this->question($fellowship);
        }

        $session = $this->generateSession($fellowship, ['question_count' => 4]);

        $this->answerCurrent($session, correctly: true)->assertOk();
        $this->answerCurrent($session, correctly: false)->assertOk();

        $this->postJson($this->sessionsUrl."/{$session->id}/pause")->assertOk();

        $this->assertFalse($session->refresh()->isCompleted());

        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")
            ->assertOk()
            ->assertJsonPath('data.average_score', 50);
    }

    public function test_a_repeated_attempt_on_the_same_question_counts_as_a_second_answer(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        // A single question in the bank, answered right once and wrong once in
        // two different sessions.
        $this->question($fellowship);

        $first = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($first, correctly: true)->assertOk();

        $second = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($second, correctly: false)->assertOk();

        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")
            ->assertOk()
            // 1 of 2 answers, not 1 of 1 question.
            ->assertJsonPath('data.average_score', 50)
            ->assertJsonPath('data.topic_performance.0.answered_questions', 2);
    }

    public function test_topics_completed_counts_topics_with_at_least_one_confirmed_answer(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $cardio = $this->question($fellowship, topic: 'Cardiology');
        $this->question($fellowship, topic: 'Neurology');
        $this->question($fellowship, topic: 'Renal');

        $filter = $fellowship->performanceFilter;
        $cardioValueId = $filter->values()->where('value', 'Cardiology')->value('id');

        $session = $this->generateSession($fellowship, [
            'question_count' => 1,
            'filters' => [$filter->getKey() => [$cardioValueId]],
        ]);
        $this->answerCurrent($session)->assertOk();

        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")
            ->assertOk()
            ->assertJsonPath('data.topics_completed', 1)
            ->assertJsonCount(3, 'data.performance_topics');
    }

    public function test_topic_performance_is_per_topic_accuracy(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $filter = $fellowship->performanceFilter;

        foreach (range(1, 2) as $ignored) {
            $this->question($fellowship, topic: 'Cardiology');
        }
        $this->question($fellowship, topic: 'Neurology');

        $cardioId = $filter->values()->where('value', 'Cardiology')->value('id');
        $neuroId = $filter->values()->where('value', 'Neurology')->value('id');

        // Cardiology: one right, one wrong.
        $cardio = $this->generateSession($fellowship, [
            'question_count' => 2,
            'filters' => [$filter->getKey() => [$cardioId]],
        ]);
        $this->answerAll($cardio, correct: 1);

        // Neurology: one right.
        $neuro = $this->generateSession($fellowship, [
            'question_count' => 1,
            'filters' => [$filter->getKey() => [$neuroId]],
        ]);
        $this->answerAll($neuro);

        $topics = collect($this->getJson($this->fellowshipsUrl."/{$fellowship->id}")->assertOk()->json('data.topic_performance'))
            ->keyBy('topic_value');

        $this->assertSame(50, $topics['Cardiology']['average_score']);
        $this->assertSame(2, $topics['Cardiology']['answered_questions']);
        $this->assertSame(100, $topics['Neurology']['average_score']);
        $this->assertSame(1, $topics['Neurology']['answered_questions']);
    }

    public function test_topic_performance_reads_the_topic_captured_in_the_session(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, topic: 'Cardiology');

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($session)->assertOk();

        // The admin re-files the question under a different topic afterwards.
        $question->update(['performance_filter_value_id' => $this->topicValue($fellowship, 'Neurology')->getKey()]);

        $topics = app(FellowshipAnalyticsService::class)->topicPerformance($student, $fellowship);

        $this->assertCount(1, $topics);
        $this->assertSame('Cardiology', $topics[0]['topic_value'], 'History stays under the topic it was answered in.');
    }

    public function test_quiz_performance_reports_every_difficulty_level(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, difficulty: QuizDifficulty::Easy);
        $this->question($fellowship, difficulty: QuizDifficulty::Easy);

        $session = $this->generateSession($fellowship, [
            'question_count' => 2,
            'difficulties' => [QuizDifficulty::Easy->value],
        ]);
        $this->answerAll($session, correct: 1);

        $levels = collect($this->getJson($this->fellowshipsUrl."/{$fellowship->id}")->assertOk()->json('data.quiz_performance'))
            ->keyBy('difficulty');

        $this->assertCount(3, $levels);
        $this->assertSame(50, $levels['easy']['average_score']);
        $this->assertNull($levels['hard']['average_score']);
        $this->assertSame(0, $levels['hard']['answered_questions']);
    }

    public function test_the_day_streak_counts_consecutive_days_with_a_confirmed_answer(): void
    {
        $student = $this->actingAsStudent();
        config(['fellowship.timezone' => 'UTC']);

        $fellowship = $this->fellowship();

        foreach (range(1, 3) as $ignored) {
            $this->question($fellowship);
        }

        foreach (['2026-10-10 10:00:00', '2026-10-11 10:00:00', '2026-10-12 10:00:00'] as $day) {
            Carbon::setTestNow(Carbon::parse($day));

            $session = $this->generateSession($fellowship, ['question_count' => 1]);
            $this->answerCurrent($session)->assertOk();
        }

        Carbon::setTestNow(Carbon::parse('2026-10-12 23:00:00'));

        $this->assertSame(3, app(FellowshipAnalyticsService::class)->dayStreak($student, $fellowship));
    }

    public function test_a_gap_breaks_the_streak(): void
    {
        $student = $this->actingAsStudent();
        config(['fellowship.timezone' => 'UTC']);

        $fellowship = $this->fellowship();

        foreach (range(1, 3) as $ignored) {
            $this->question($fellowship);
        }

        foreach (['2026-10-08 10:00:00', '2026-10-11 10:00:00', '2026-10-12 10:00:00'] as $day) {
            Carbon::setTestNow(Carbon::parse($day));

            $session = $this->generateSession($fellowship, ['question_count' => 1]);
            $this->answerCurrent($session)->assertOk();
        }

        Carbon::setTestNow(Carbon::parse('2026-10-12 20:00:00'));

        $this->assertSame(2, app(FellowshipAnalyticsService::class)->dayStreak($student, $fellowship));
    }

    public function test_a_streak_is_not_broken_before_today_is_over(): void
    {
        $student = $this->actingAsStudent();
        config(['fellowship.timezone' => 'UTC']);

        $fellowship = $this->fellowship();
        $this->question($fellowship);
        $this->question($fellowship);

        foreach (['2026-10-10 10:00:00', '2026-10-11 10:00:00'] as $day) {
            Carbon::setTestNow(Carbon::parse($day));

            $session = $this->generateSession($fellowship, ['question_count' => 1]);
            $this->answerCurrent($session)->assertOk();
        }

        // Today, with nothing answered yet: yesterday still counts.
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00:00'));

        $this->assertSame(2, app(FellowshipAnalyticsService::class)->dayStreak($student, $fellowship));
    }

    public function test_a_long_open_session_is_not_a_streak(): void
    {
        $student = $this->actingAsStudent();
        config(['fellowship.timezone' => 'UTC']);

        $fellowship = $this->fellowship();
        $this->question($fellowship);

        // A session opened a week ago and answered only today. Leaving it open
        // across those days must not manufacture a streak.
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00'));
        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00'));
        $this->answerCurrent($session)->assertOk();

        $this->assertSame(1, app(FellowshipAnalyticsService::class)->dayStreak($student, $fellowship));
    }

    public function test_the_streak_uses_the_configured_timezone_for_day_boundaries(): void
    {
        $student = $this->actingAsStudent();

        // 23:30 UTC on the 11th is 01:30 on the 12th in Cairo.
        config(['fellowship.timezone' => 'Africa/Cairo']);

        $fellowship = $this->fellowship();
        $this->question($fellowship);

        Carbon::setTestNow(Carbon::parse('2026-10-11 23:30:00', 'UTC'));
        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($session)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00', 'UTC'));

        $this->assertSame(
            1,
            app(FellowshipAnalyticsService::class)->dayStreak($student, $fellowship),
            'The answer falls on the 12th in Cairo, which is today.',
        );
    }

    public function test_no_answers_means_no_score_rather_than_a_zero(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $response = $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")->assertOk();

        $response->assertJsonPath('data.average_score', null);
        $response->assertJsonPath('data.topics_completed', 0);
        $response->assertJsonPath('data.day_streak', 0);
        $response->assertJsonPath('data.study_time_seconds', 0);

        $this->assertSame(0, app(FellowshipAnalyticsService::class)->dayStreak($student, $fellowship));
    }

    public function test_recent_activity_lists_the_newest_answers_first(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, text: 'First question?');
        $this->question($fellowship, text: 'Second question?');

        $session = $this->generateSession($fellowship, ['question_count' => 2]);

        $firstAnswered = $session->currentQuestion()->snapshot()->questionText;
        $this->answerCurrent($session)->assertOk();

        Carbon::setTestNow(now()->addMinute());
        $secondAnswered = $session->refresh()->currentQuestion()->snapshot()->questionText;
        $this->answerCurrent($session)->assertOk();

        $activity = $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")->assertOk()->json('data.recent_activity');

        $this->assertCount(2, $activity);
        $this->assertSame($secondAnswered, $activity[0]['question_text']);
        $this->assertSame($firstAnswered, $activity[1]['question_text']);
        $this->assertSame($session->title, $activity[0]['session_title']);
    }

    public function test_fellowship_statistics_are_scoped_to_one_student(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);
        $this->question($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($session);

        // A second student sees nothing of the first's work.
        $this->actingAsStudent();

        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")
            ->assertOk()
            ->assertJsonPath('data.average_score', null)
            ->assertJsonPath('data.topics_completed', 0)
            ->assertJsonPath('data.study_time_seconds', 0);
    }

    public function test_fellowship_statistics_are_scoped_to_one_fellowship(): void
    {
        $this->actingAsStudent();

        $first = $this->fellowship('First');
        $second = $this->fellowship('Second');

        $this->question($first);
        $this->question($second);

        $session = $this->generateSession($first, ['question_count' => 1]);
        $this->answerCurrent($session, correctly: false)->assertOk();

        $this->getJson($this->fellowshipsUrl."/{$first->id}")->assertOk()->assertJsonPath('data.average_score', 0);
        $this->getJson($this->fellowshipsUrl."/{$second->id}")->assertOk()->assertJsonPath('data.average_score', null);
    }
}
