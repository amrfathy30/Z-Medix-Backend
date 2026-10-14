<?php

namespace Tests\Feature\Fellowship;

use App\Services\Fellowship\FellowshipAnalyticsService;
use Illuminate\Support\Facades\DB;

/**
 * The session analytics queries must not order by a column they neither select
 * nor group.
 *
 * `FellowshipQuizSession::questions()` orders by `position`, which is right for
 * serving a session in order and wrong the moment that relation is reused to
 * aggregate: a `DISTINCT topic_value` or a `GROUP BY performance_filter_value_id,
 * topic_value` carrying `ORDER BY position` is rejected outright by MySQL under
 * `ONLY_FULL_GROUP_BY` — which is how the production database runs.
 *
 * This is asserted on the SQL rather than by executing against MySQL because
 * the suite runs on SQLite, and SQLite accepts the invalid shape happily. That
 * permissiveness is exactly why the fault reached a running system in the first
 * place: every one of these queries worked in the test suite and failed on the
 * first real request. Pinning the shape is therefore the regression guard that
 * holds wherever the suite runs.
 *
 * Removing the `reorder()` from either query makes these tests fail.
 */
class FellowshipAnalyticsGroupingSqlTest extends StudentFellowshipTestCase
{
    /**
     * Statements executed while running the given work.
     *
     * @return list<string>
     */
    private function captureQueries(callable $work): array
    {
        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $work();

        return $statements;
    }

    /**
     * The invalid shape: a statement that collapses rows — DISTINCT or GROUP BY
     * — while ordering by a column it does not select.
     *
     * @param  list<string>  $statements
     * @return list<string>
     */
    private function collapsingStatementsOrderedByPosition(array $statements): array
    {
        return array_values(array_filter($statements, static function (string $sql): bool {
            $normalized = mb_strtolower($sql);

            $collapses = str_contains($normalized, 'select distinct')
                || str_contains($normalized, 'group by');

            if (! $collapses) {
                return false;
            }

            $orderBy = mb_strstr($normalized, 'order by');

            return $orderBy !== false && str_contains($orderBy, 'position');
        }));
    }

    public function test_session_topics_does_not_order_by_position(): void
    {
        $this->actingAsStudent();

        $fellowship = $this->fellowship();
        $this->question($fellowship, topic: 'Cardiology');
        $this->question($fellowship, topic: 'Neurology');

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($session);

        $statements = $this->captureQueries(
            fn () => app(FellowshipAnalyticsService::class)->sessionTopics($session->refresh()),
        );

        $this->assertSame(
            [],
            $this->collapsingStatementsOrderedByPosition($statements),
            'sessionTopics() issued a DISTINCT ordered by `position`, which MySQL refuses under ONLY_FULL_GROUP_BY.',
        );
    }

    public function test_session_topic_performance_does_not_order_by_position(): void
    {
        $this->actingAsStudent();

        $fellowship = $this->fellowship();
        $this->question($fellowship, topic: 'Cardiology');
        $this->question($fellowship, topic: 'Neurology');

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($session);

        $statements = $this->captureQueries(
            fn () => app(FellowshipAnalyticsService::class)->sessionTopicPerformance($session->refresh()),
        );

        $this->assertSame(
            [],
            $this->collapsingStatementsOrderedByPosition($statements),
            'sessionTopicPerformance() issued a GROUP BY ordered by `position`, which MySQL refuses under ONLY_FULL_GROUP_BY.',
        );
    }

    /**
     * Dropping the inherited order must not have cost the ordering these
     * methods are specified to return.
     */
    public function test_session_topics_are_still_returned_in_topic_order(): void
    {
        $this->actingAsStudent();

        $fellowship = $this->fellowship();
        $this->question($fellowship, topic: 'Neurology');
        $this->question($fellowship, topic: 'Cardiology');
        $this->question($fellowship, topic: 'Respiratory');

        $session = $this->generateSession($fellowship, ['question_count' => 3]);
        $this->answerAll($session);

        $topics = app(FellowshipAnalyticsService::class)->sessionTopics($session->refresh());

        $this->assertSame(['Cardiology', 'Neurology', 'Respiratory'], $topics);
    }

    public function test_session_topic_performance_is_still_grouped_by_captured_topic(): void
    {
        $this->actingAsStudent();

        $fellowship = $this->fellowship();
        $this->question($fellowship, topic: 'Neurology');
        $this->question($fellowship, topic: 'Cardiology');

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($session);

        $rows = app(FellowshipAnalyticsService::class)->sessionTopicPerformance($session->refresh());

        $this->assertCount(2, $rows);
        $this->assertSame('Cardiology', $rows[0]['topic_value']);
        $this->assertSame('Neurology', $rows[1]['topic_value']);
        $this->assertSame(1, $rows[0]['answered_questions']);
        $this->assertSame(100.0, $rows[0]['average_score']);
    }

    /**
     * The Fellowship-wide breakdown shares `topicRows()` with the session one,
     * so it must stay valid too.
     */
    public function test_fellowship_topic_performance_does_not_order_by_position(): void
    {
        $student = $this->actingAsStudent();

        $fellowship = $this->fellowship();
        $this->question($fellowship, topic: 'Cardiology');

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerAll($session);

        $statements = $this->captureQueries(
            fn () => app(FellowshipAnalyticsService::class)->topicPerformance($student, $fellowship),
        );

        $this->assertSame(
            [],
            $this->collapsingStatementsOrderedByPosition($statements),
            'topicPerformance() issued a GROUP BY ordered by `position`.',
        );
    }
}
