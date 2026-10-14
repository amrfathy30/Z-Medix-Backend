<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipSessionStatus;
use Illuminate\Support\Carbon;

/**
 * Session History and the result screen: per-session figures, pagination, and
 * which of Resume or View Result a row offers.
 */
class FellowshipSessionHistoryTest extends StudentFellowshipTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_history_lists_the_students_sessions_newest_first(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);
        $this->question($fellowship);

        Carbon::setTestNow(Carbon::parse('2026-10-10 09:00:00'));
        $this->generate($fellowship, ['title' => 'Older', 'question_count' => 1])->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $this->generate($fellowship, ['title' => 'Newer', 'question_count' => 1])->assertCreated();

        $response = $this->getJson($this->fellowshipsUrl."/{$fellowship->id}/sessions")->assertOk();

        $response->assertJsonPath('data.0.title', 'Newer');
        $response->assertJsonPath('data.1.title', 'Older');
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_history_is_paginated(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);

        foreach (range(1, 5) as $index) {
            $this->generate($fellowship, ['title' => "Session {$index}", 'question_count' => 1])->assertCreated();
        }

        $response = $this->getJson($this->fellowshipsUrl."/{$fellowship->id}/sessions?per_page=2")->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.last_page', 3);
        $this->assertNotNull($response->json('links.next'));

        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}/sessions?per_page=2&page=3")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3);
    }

    public function test_a_history_row_carries_the_sessions_own_figures(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, topic: 'Cardiology');
        $this->question($fellowship, topic: 'Neurology');

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->generateSession($fellowship, ['title' => 'Mixed bag', 'question_count' => 2]);

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:04:00'));
        $this->answerAll($session, correct: 1);

        $row = $this->getJson($this->fellowshipsUrl."/{$fellowship->id}/sessions")->assertOk()->json('data.0');

        $this->assertSame($session->getKey(), $row['id']);
        $this->assertSame('Mixed bag', $row['title']);
        $this->assertSame(FellowshipSessionStatus::Completed->value, $row['status']);
        $this->assertSame(2, $row['total_questions']);
        $this->assertSame(1, $row['correct_answers']);
        $this->assertSame(1, $row['incorrect_answers']);
        $this->assertSame(50, $row['average_score']);
        $this->assertSame(240, $row['study_time_seconds']);
        $this->assertEqualsCanonicalizing(['Cardiology', 'Neurology'], $row['performance_topics']);
    }

    public function test_an_unfinished_row_offers_resume_and_a_finished_one_offers_its_result(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);
        $this->question($fellowship);

        $open = $this->generateSession($fellowship, ['title' => 'Still going', 'question_count' => 2]);
        $this->answerCurrent($open)->assertOk();

        $done = $this->generateSession($fellowship, ['title' => 'Finished', 'question_count' => 2]);
        $this->answerAll($done);

        $rows = collect($this->getJson($this->fellowshipsUrl."/{$fellowship->id}/sessions")->assertOk()->json('data'))
            ->keyBy('title');

        $this->assertTrue($rows['Still going']['can_resume']);
        $this->assertFalse($rows['Still going']['can_view_result']);
        // Where resuming reopens: the question the student is on.
        $this->assertSame(2, $rows['Still going']['current_position']);

        $this->assertFalse($rows['Finished']['can_resume']);
        $this->assertTrue($rows['Finished']['can_view_result']);
    }

    public function test_history_shows_only_the_requesting_students_sessions(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $this->generate($fellowship, ['title' => 'Mine', 'question_count' => 1])->assertCreated();

        $this->actingAsStudent();

        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}/sessions")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_history_is_scoped_to_the_fellowship_asked_about(): void
    {
        $this->actingAsStudent();

        $first = $this->fellowship('First');
        $second = $this->fellowship('Second');
        $this->question($first);
        $this->question($second);

        $this->generate($first, ['title' => 'In first', 'question_count' => 1])->assertCreated();
        $this->generate($second, ['title' => 'In second', 'question_count' => 1])->assertCreated();

        $this->getJson($this->fellowshipsUrl."/{$first->id}/sessions")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'In first');
    }

    public function test_a_completed_result_carries_the_review_of_every_question(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, topic: 'Cardiology');
        $this->question($fellowship, topic: 'Cardiology');

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:00:00'));
        $session = $this->generateSession($fellowship, ['question_count' => 2, 'timer_enabled' => true]);

        Carbon::setTestNow(Carbon::parse('2026-10-12 09:02:00'));
        $this->answerAll($session, correct: 1);

        $response = $this->getJson($this->sessionsUrl."/{$session->id}/result")->assertOk();

        $response->assertJsonPath('data.status', FellowshipSessionStatus::Completed->value);
        $response->assertJsonPath('data.total_questions', 2);
        $response->assertJsonPath('data.correct_answers', 1);
        $response->assertJsonPath('data.incorrect_answers', 1);
        $response->assertJsonPath('data.average_score', 50);
        $response->assertJsonPath('data.study_time_seconds', 120);
        $response->assertJsonPath('data.topic_performance.0.topic_value', 'Cardiology');
        $response->assertJsonPath('data.topic_performance.0.average_score', 50);

        $questions = $response->json('data.questions');

        $this->assertCount(2, $questions);
        $this->assertSame([1, 2], array_column($questions, 'position'));

        foreach ($questions as $question) {
            $this->assertNotNull($question['selected_option_key']);
            $this->assertNotNull($question['selected_option_text']);
            $this->assertNotNull($question['correct_option_key']);
            $this->assertNotNull($question['correct_option_text']);
            $this->assertNotNull($question['explanation']);
            $this->assertIsBool($question['is_correct']);
            $this->assertIsBool($question['answered_within_time']);
        }
    }

    public function test_session_level_figures_do_not_aggregate_other_sessions(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);
        $this->question($fellowship);

        // One session all wrong, one all right.
        $wrong = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($wrong, correct: 0);

        $right = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($right);

        $this->getJson($this->sessionsUrl."/{$wrong->id}/result")->assertOk()->assertJsonPath('data.average_score', 0);
        $this->getJson($this->sessionsUrl."/{$right->id}/result")->assertOk()->assertJsonPath('data.average_score', 100);

        // The fellowship aggregates both.
        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")->assertOk()->assertJsonPath('data.average_score', 50);
    }

    public function test_an_unfinished_session_reports_what_has_been_answered_so_far(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);
        $this->question($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerCurrent($session)->assertOk();

        $this->getJson($this->sessionsUrl."/{$session->id}/result")
            ->assertOk()
            ->assertJsonPath('data.total_questions', 2)
            ->assertJsonPath('data.answered_questions', 1)
            ->assertJsonPath('data.average_score', 100);
    }
}
