<?php

namespace App\Support\Smoke\Fellowship\Scenarios;

use App\Models\FellowshipFilterValue;
use App\Models\FellowshipQuestion;
use App\Services\Fellowship\FellowshipFilterService;
use App\Support\Fellowship\FellowshipClock;
use App\Support\Smoke\Fellowship\FellowshipSmokeContext;
use App\Support\Smoke\Fellowship\FellowshipSmokeFixtures;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the student's screens report, checked against the answers actually
 * stored.
 *
 * Two claims run through all of it, and both are the kind that look right until
 * the data gets awkward.
 *
 * The first is what counts as an answer: every confirmed answer, the moment it
 * is given, including answers in a session the student never finished, and
 * counting a question answered twice in two sessions as two answers. The
 * denominator is the answers given — never the size of the question bank, which
 * would quietly turn a score into a coverage figure.
 *
 * The second is where a topic comes from: the session question's own captured
 * topic, never the live one. So the interesting tests here are the destructive
 * ones — reword a question, move its correct answer, re-file its topic, delete
 * it outright — and then check that nothing a student has already earned moved.
 */
class FellowshipAnalyticsScenario
{
    public function run(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;

        if ($context->fellowship === null || ! $context->asStudent()) {
            $ledger->skip('Analytics', 'No Fellowship or authenticated student.');

            return;
        }

        try {
            $this->answersFromUnfinishedSessionsCount($context);
            $this->repeatedQuestionsCountSeparately($context);
            $this->averageScoreAgreesEverywhere($context);
            $this->studyTimeAndStreak($context);
            $this->studyTimeAcrossMidnight($context);
            $this->topicAndQuizPerformance($context);
            $this->snapshotsSurviveEditing($context);
            $this->snapshotsSurviveTopicReassignment($context);
            $this->recreatedTopicStaysHistoricallyDistinct($context);
            $this->snapshotsSurviveDeletion($context);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ── What counts as an answer ──────────────────────────────────────────────

    /**
     * An answer in a session the student has not finished counts the moment it
     * is given.
     */
    private function answersFromUnfinishedSessionsCount(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Analytics · Average score');

        $student = $context->student;
        $fellowship = $context->fellowship;

        if ($student === null || $fellowship === null) {
            return;
        }

        $unfinished = DB::table('fellowship_quiz_sessions as s')
            ->join('fellowship_session_questions as sq', 'sq.fellowship_quiz_session_id', '=', 's.id')
            ->where('s.user_id', $student->getKey())
            ->where('s.fellowship_id', $fellowship->getKey())
            ->where('s.status', '<>', 'completed')
            ->whereNotNull('sq.answered_at')
            ->count();

        $ledger->check(
            'The student has confirmed answers in an unfinished session',
            $unfinished > 0,
            'No unfinished session carries a confirmed answer, so this cannot be checked.',
        );

        $expected = $this->expectedAverageScore($context);
        $response = $context->api->get('/api/student/fellowships/'.$fellowship->getKey());

        $ledger->sameNumber(
            'The average score counts answers from unfinished sessions',
            $expected,
            $response->json('data.average_score'),
            $response,
        );

        // The figure must not be the bank-coverage one.
        $bankSize = (int) DB::table('fellowship_questions')
            ->where('fellowship_id', $fellowship->getKey())
            ->whereNull('deleted_at')
            ->count();

        $correct = $this->confirmedCorrect($context);
        $coverage = round($correct / max(1, $bankSize) * 100, 2);

        $ledger->check(
            'The average score is not the share of the question bank answered',
            abs($coverage - (float) $expected) > 0.001,
            sprintf(
                'The two figures coincide here (%s), so this run cannot tell them apart.',
                (string) $expected,
            ),
        );

        $ledger->note('Confirmed answers so far', $this->confirmedAnswers($context));
        $ledger->note('Average score', (string) $expected);
    }

    /**
     * A question answered again in a later session is a second answer, not a
     * correction of the first.
     */
    private function repeatedQuestionsCountSeparately(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;

        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            return;
        }

        $id = $fellowship->getKey();
        $before = $this->confirmedAnswers($context);

        // Draw only questions this student has already answered, so the session
        // is made entirely of repeats.
        $created = $context->api->post('/api/student/fellowships/'.$id.'/sessions', [
            'title' => FellowshipSmokeFixtures::MARKER.'Repeats',
            'question_count' => 2,
            'question_status' => 'answered',
        ]);

        if (! $ledger->same('A session of already-answered questions can be generated', 201, $created->status, $created)) {
            return;
        }

        $sessionId = (int) $created->json('data.id');
        $context->sessions['repeats'] = $sessionId;

        $questions = $created->json('data.questions', []);
        $drawnIds = array_column($questions, 'fellowship_question_id');

        $previouslyAnswered = DB::table('fellowship_session_questions as sq')
            ->join('fellowship_quiz_sessions as s', 's.id', '=', 'sq.fellowship_quiz_session_id')
            ->where('s.user_id', $context->student?->getKey())
            ->where('s.fellowship_id', $id)
            ->where('s.id', '<>', $sessionId)
            ->whereNotNull('sq.answered_at')
            ->pluck('sq.fellowship_question_id')
            ->map(static fn ($value): int => (int) $value)
            ->unique()
            ->all();

        $ledger->check(
            'Every question drawn was one the student had answered before',
            array_diff($drawnIds, $previouslyAnswered) === [],
            'The Answered filter drew a question with no prior answer: '
                .implode(', ', array_diff($drawnIds, $previouslyAnswered)),
            $created,
        );

        // Answer them all, deliberately getting both wrong, so the repeat is
        // visible in the score rather than hidden behind an identical result.
        foreach ($questions as $question) {
            $correctKey = $this->correctKeyFor((int) $question['id']);
            $wrongKey = null;

            foreach ($question['options'] ?? [] as $option) {
                if ((string) $option['key'] !== $correctKey) {
                    $wrongKey = (string) $option['key'];

                    break;
                }
            }

            if ($wrongKey === null) {
                continue;
            }

            $context->api->post(
                '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$question['id'].'/answer',
                ['option_key' => $wrongKey],
            );
        }

        $after = $this->confirmedAnswers($context);

        $ledger->same(
            'Re-answering a question adds an answer rather than replacing one',
            $before + count($questions),
            $after,
        );

        // The same live question now carries more than one confirmed answer.
        $withRepeats = (int) DB::table('fellowship_session_questions as sq')
            ->join('fellowship_quiz_sessions as s', 's.id', '=', 'sq.fellowship_quiz_session_id')
            ->where('s.user_id', $context->student?->getKey())
            ->where('s.fellowship_id', $id)
            ->whereNotNull('sq.answered_at')
            ->whereIn('sq.fellowship_question_id', $drawnIds === [] ? [0] : $drawnIds)
            ->count();

        $ledger->check(
            'A repeated question holds several confirmed answers',
            $withRepeats > count($drawnIds),
            sprintf('%d answers across %d repeated questions.', $withRepeats, count($drawnIds)),
        );

        $expected = $this->expectedAverageScore($context);
        $response = $context->api->get('/api/student/fellowships/'.$context->fellowship?->getKey());

        $ledger->sameNumber(
            'The average score includes the repeat attempts',
            $expected,
            $response->json('data.average_score'),
            $response,
        );
    }

    /**
     * One figure, computed one way, wherever it appears.
     */
    private function averageScoreAgreesEverywhere(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            return;
        }

        $id = $fellowship->getKey();
        $expected = $this->expectedAverageScore($context);

        $list = $context->api->get('/api/student/fellowships');
        $listed = null;

        foreach ($list->data() as $item) {
            if (($item['id'] ?? null) === $id) {
                $listed = $item;
            }
        }

        $ledger->check('The Fellowship list uses the key "average_score"', is_array($listed) && array_key_exists('average_score', $listed), 'Key absent from the list row.', $list);
        $ledger->sameNumber('The Fellowship list reports the same score', $expected, $listed['average_score'] ?? null, $list);

        $dashboard = $context->api->get('/api/student/fellowships/'.$id);

        $ledger->check('The dashboard uses the key "average_score"', array_key_exists('average_score', $dashboard->data()), 'Key absent from the dashboard.', $dashboard);
        $ledger->sameNumber('The dashboard reports the same score', $expected, $dashboard->json('data.average_score'), $dashboard);

        $sessionId = $context->sessions['full'] ?? null;

        if ($sessionId !== null) {
            $result = $context->api->get('/api/student/fellowship-sessions/'.$sessionId.'/result');

            $ledger->check(
                'The session result uses the key "average_score"',
                array_key_exists('average_score', $result->data()),
                'Key absent from the result.',
                $result,
            );

            // The session figure is the same formula over one session, so it is
            // computed the same way without having to equal the Fellowship-wide
            // number.
            $sessionExpected = $this->expectedSessionScore($sessionId);

            $ledger->sameNumber(
                'The session result scores that session the same way',
                $sessionExpected,
                $result->json('data.average_score'),
                $result,
            );
        }
    }

    // ── Study time and streak ─────────────────────────────────────────────────

    private function studyTimeAndStreak(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Analytics · Study time and streak');

        $fellowship = $context->fellowship;
        $student = $context->student;

        if ($fellowship === null || $student === null) {
            return;
        }

        $id = $fellowship->getKey();
        $dashboard = $context->api->get('/api/student/fellowships/'.$id);

        // Total study time is the sum of the activity periods, with any still
        // open counted up to now.
        $closed = (int) DB::table('fellowship_session_periods as p')
            ->join('fellowship_quiz_sessions as s', 's.id', '=', 'p.fellowship_quiz_session_id')
            ->where('s.user_id', $student->getKey())
            ->where('s.fellowship_id', $id)
            ->whereNotNull('p.ended_at')
            ->sum('p.duration_seconds');

        $reported = (int) $dashboard->json('data.study_time_seconds');

        $ledger->check(
            'Total study time is at least the sum of the closed periods',
            $reported >= $closed,
            sprintf('reported %d, closed periods total %d.', $reported, $closed),
            $dashboard,
        );

        $ledger->check(
            'Total study time is not simply how long the sessions have existed',
            $reported > 0,
            'No study time was reported at all.',
            $dashboard,
        );

        // Daily study time: a fixed window whose entries sum to the time spent
        // inside it.
        $daily = $dashboard->json('data.daily_study_time', []);

        $ledger->same('Daily study time covers seven days by default', 7, count($daily), $dashboard);

        $dates = array_column($daily, 'date');
        $sortedDates = $dates;
        sort($sortedDates);

        $ledger->same('Daily study time is in date order', $sortedDates, $dates, $dashboard);
        $ledger->same(
            'Daily study time ends on today in the module\'s timezone',
            FellowshipClock::today()->toDateString(),
            end($dates),
            $dashboard,
        );

        $ledger->check(
            'Every day in the window carries a number, zero included',
            count(array_filter($daily, static fn (array $day): bool => is_int($day['seconds']) && $day['seconds'] >= 0)) === count($daily),
            'A day in the window had no numeric seconds: '.json_encode($daily),
            $dashboard,
        );

        $ledger->check(
            'Daily study time accounts for time spent today',
            (int) (end($daily)['seconds'] ?? 0) > 0,
            'Today shows no study time although the student has been working.',
            $dashboard,
        );

        // A longer window is accepted and returns that many days.
        $longer = $context->api->get('/api/student/fellowships/'.$id.'?days=14');

        $ledger->same('A fourteen-day window returns fourteen days', 14, count($longer->json('data.daily_study_time', [])), $longer);

        // Topics completed: distinct topics with at least one confirmed answer.
        $distinctTopics = (int) DB::table('fellowship_session_questions as sq')
            ->join('fellowship_quiz_sessions as s', 's.id', '=', 'sq.fellowship_quiz_session_id')
            ->where('s.user_id', $student->getKey())
            ->where('s.fellowship_id', $id)
            ->whereNotNull('sq.answered_at')
            ->whereNotNull('sq.topic_value')
            ->distinct()
            ->count(DB::raw('concat(coalesce(sq.performance_filter_value_id, 0), "|", sq.topic_value)'));

        $ledger->same(
            'Topics completed counts the topics actually answered in',
            $distinctTopics,
            $dashboard->json('data.topics_completed'),
            $dashboard,
        );

        // Recent activity, newest first.
        $activity = $dashboard->json('data.recent_activity', []);

        $ledger->check('Recent activity lists the student\'s answers', $activity !== [], 'Recent activity was empty.', $dashboard);

        $answeredAt = array_column($activity, 'answered_at');
        $sortedDesc = $answeredAt;
        rsort($sortedDesc);

        $ledger->same('Recent activity is newest first', $sortedDesc, $answeredAt, $dashboard);
        $ledger->check(
            'Recent activity is capped at ten entries',
            count($activity) <= 10,
            'Recent activity returned '.count($activity).' entries.',
            $dashboard,
        );

        $this->dayStreakCountsAnswerDays($context);
    }

    /**
     * The streak is built from the days answers were confirmed on, not from how
     * long a session was left open.
     *
     * A second day of history is created by moving the clock back, answering,
     * and closing that session's period before returning — so the earlier day
     * leaves a finished period in the past rather than one that starts in the
     * future.
     */
    private function dayStreakCountsAnswerDays(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            return;
        }

        $id = $fellowship->getKey();

        $before = (int) $context->api->get('/api/student/fellowships/'.$id)->json('data.day_streak');

        $ledger->check(
            'Answering today gives a streak of at least one day',
            $before >= 1,
            'The streak was '.$before.' although the student answered today.',
        );

        // Yesterday, mid-afternoon in the module's timezone.
        $yesterday = FellowshipClock::today()->subDay()->addHours(14);

        $this->travelTo($yesterday);

        $created = $context->api->post('/api/student/fellowships/'.$id.'/sessions', [
            'title' => FellowshipSmokeFixtures::MARKER.'Yesterday',
            'question_count' => 1,
        ]);

        if ($created->status === 201) {
            $sessionId = (int) $created->json('data.id');
            $context->sessions['yesterday'] = $sessionId;

            $question = $created->json('data.questions.0', []);

            $this->travelTo($yesterday->copy()->addMinutes(4));

            $context->api->post(
                '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$question['id'].'/answer',
                ['option_key' => (string) $this->correctKeyFor((int) $question['id'])],
            );

            // Close the day's period before leaving it.
            $this->travelTo($yesterday->copy()->addMinutes(6));
            $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/pause');

            Carbon::setTestNow();

            $ledger->same(
                'Yesterday\'s answer was recorded on yesterday\'s date',
                $yesterday->toDateString(),
                FellowshipClock::dateOf((string) DB::table('fellowship_session_questions')
                    ->where('fellowship_quiz_session_id', $sessionId)
                    ->value('answered_at')),
            );

            $after = $context->api->get('/api/student/fellowships/'.$id);

            $ledger->same(
                'Answering yesterday and today gives a two-day streak',
                2,
                $after->json('data.day_streak'),
                $after,
            );

            // Yesterday's study time lands on yesterday, not on today.
            $daily = collect($after->json('data.daily_study_time', []))->keyBy('date');

            $ledger->check(
                'Yesterday\'s study time is attributed to yesterday',
                (int) ($daily[$yesterday->toDateString()]['seconds'] ?? 0) > 0,
                'Yesterday shows no study time: '.json_encode($after->json('data.daily_study_time')),
                $after,
            );
        } else {
            Carbon::setTestNow();
            $ledger->skip('Answering yesterday and today gives a two-day streak', 'Could not generate a session on the earlier date.');
        }

        // A gap must break the streak: an answer three days ago does not extend
        // a run that has nothing in between.
        $threeDaysAgo = FellowshipClock::today()->subDays(3)->addHours(10);

        $this->travelTo($threeDaysAgo);

        $gapSession = $context->api->post('/api/student/fellowships/'.$id.'/sessions', [
            'title' => FellowshipSmokeFixtures::MARKER.'Three days ago',
            'question_count' => 1,
        ]);

        if ($gapSession->status === 201) {
            $sessionId = (int) $gapSession->json('data.id');
            $context->sessions['gap'] = $sessionId;
            $question = $gapSession->json('data.questions.0', []);

            $this->travelTo($threeDaysAgo->copy()->addMinutes(3));

            $context->api->post(
                '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$question['id'].'/answer',
                ['option_key' => (string) $this->correctKeyFor((int) $question['id'])],
            );

            $this->travelTo($threeDaysAgo->copy()->addMinutes(5));
            $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/pause');

            Carbon::setTestNow();

            $after = $context->api->get('/api/student/fellowships/'.$id);

            $ledger->same(
                'A day with no answer breaks the streak rather than bridging it',
                2,
                $after->json('data.day_streak'),
                $after,
            );
        } else {
            Carbon::setTestNow();
            $ledger->skip('A gap breaks the streak', 'Could not generate a session on the earlier date.');
        }
    }

    /**
     * A period that runs past midnight belongs to both days.
     */
    private function studyTimeAcrossMidnight(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Analytics · Midnight split');

        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            return;
        }

        $id = $fellowship->getKey();

        // Ten to midnight, two days back, so the span sits entirely inside the
        // reported window and away from today's own activity.
        $start = FellowshipClock::today()->subDays(2)->subMinutes(10);
        $startDate = FellowshipClock::dateOf($start);
        $endDate = FellowshipClock::dateOf($start->copy()->addMinutes(30));

        if ($startDate === $endDate) {
            $ledger->skip('A period crossing midnight is split across both days', 'The constructed span did not cross midnight.');

            return;
        }

        $this->travelTo($start);

        $created = $context->api->post('/api/student/fellowships/'.$id.'/sessions', [
            'title' => FellowshipSmokeFixtures::MARKER.'Across midnight',
            'question_count' => 1,
        ]);

        if ($created->status !== 201) {
            Carbon::setTestNow();
            $ledger->skip('A period crossing midnight is split across both days', 'Could not generate the session.');

            return;
        }

        $sessionId = (int) $created->json('data.id');
        $context->sessions['midnight'] = $sessionId;

        // Twenty minutes past midnight: ten minutes on the first day, twenty on
        // the second.
        $this->travelTo($start->copy()->addMinutes(30));
        $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/pause');

        Carbon::setTestNow();

        $period = DB::table('fellowship_session_periods')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->first();

        $ledger->check(
            'The midnight-crossing period was closed with its full duration',
            $period !== null && (int) $period->duration_seconds === 30 * 60,
            'duration_seconds was '.var_export($period->duration_seconds ?? null, true).', expected 1800.',
        );

        // Every closed period's stored duration must agree with its own
        // timestamps. Checked over all of them, because a duration that drifts
        // from its start and end is how study time comes to disagree with the
        // chart beside it.
        $disagreeing = DB::table('fellowship_session_periods as p')
            ->join('fellowship_quiz_sessions as s', 's.id', '=', 'p.fellowship_quiz_session_id')
            ->where('s.user_id', $context->student?->getKey())
            ->whereNotNull('p.ended_at')
            ->whereRaw('p.duration_seconds <> timestampdiff(second, p.started_at, p.ended_at)')
            ->count();

        $ledger->same('Every closed period\'s duration matches its own timestamps', 0, $disagreeing);

        $dashboard = $context->api->get('/api/student/fellowships/'.$id.'?days=7');
        $daily = collect($dashboard->json('data.daily_study_time', []))->keyBy('date');

        $firstDay = (int) ($daily[$startDate]['seconds'] ?? 0);
        $secondDay = (int) ($daily[$endDate]['seconds'] ?? 0);

        $ledger->check(
            'The evening part of the period lands on the first day',
            $firstDay >= 600,
            sprintf('%s reported %d seconds; at least 600 were spent before midnight.', $startDate, $firstDay),
            $dashboard,
        );

        $ledger->check(
            'The small-hours part lands on the following day',
            $secondDay >= 1200,
            sprintf('%s reported %d seconds; at least 1200 were spent after midnight.', $endDate, $secondDay),
            $dashboard,
        );

        $ledger->note('Midnight split', $startDate.': '.$firstDay.'s / '.$endDate.': '.$secondDay.'s');
    }

    // ── Topic and difficulty breakdowns ───────────────────────────────────────

    private function topicAndQuizPerformance(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Analytics · Topic and quiz performance');

        $fellowship = $context->fellowship;
        $student = $context->student;

        if ($fellowship === null || $student === null) {
            return;
        }

        $id = $fellowship->getKey();
        $dashboard = $context->api->get('/api/student/fellowships/'.$id);

        // Topic performance, computed here from the captured topic columns.
        $expected = DB::table('fellowship_session_questions as sq')
            ->join('fellowship_quiz_sessions as s', 's.id', '=', 'sq.fellowship_quiz_session_id')
            ->where('s.user_id', $student->getKey())
            ->where('s.fellowship_id', $id)
            ->whereNotNull('sq.answered_at')
            ->selectRaw('sq.performance_filter_value_id as value_id, sq.topic_value as value, count(*) as answered, sum(case when sq.is_correct = 1 then 1 else 0 end) as correct')
            ->groupBy('sq.performance_filter_value_id', 'sq.topic_value')
            ->orderBy('sq.topic_value')
            ->get();

        $reported = collect($dashboard->json('data.topic_performance', []));

        $ledger->same('Topic performance reports one row per captured topic', $expected->count(), $reported->count(), $dashboard);

        foreach ($expected as $row) {
            $match = $reported->first(
                fn (array $item): bool => (int) ($item['topic_value_id'] ?? 0) === (int) $row->value_id
                    && ($item['topic_value'] ?? null) === $row->value,
            );

            if ($match === null) {
                $ledger->fail(
                    'Topic performance reports "'.$row->value.'"',
                    'No row for topic value id '.$row->value_id.' ("'.$row->value.'").',
                    $dashboard,
                );

                continue;
            }

            $ledger->same('Topic performance counts "'.$row->value.'" answers', (int) $row->answered, $match['answered_questions'], $dashboard);
            $ledger->same('Topic performance counts "'.$row->value.'" correct', (int) $row->correct, $match['correct_answers'], $dashboard);
            $ledger->sameNumber(
                'Topic performance scores "'.$row->value.'"',
                round((int) $row->correct / max(1, (int) $row->answered) * 100, 2),
                $match['average_score'],
                $dashboard,
            );
        }

        // Quiz Performance: one row per difficulty, always all three.
        $quiz = collect($dashboard->json('data.quiz_performance', []));

        $ledger->same('Quiz performance reports all three difficulties', 3, $quiz->count(), $dashboard);
        $ledger->same(
            'Quiz performance names the difficulties',
            ['easy', 'hard', 'medium'],
            $quiz->pluck('difficulty')->sort()->values()->all(),
            $dashboard,
        );

        foreach (['easy', 'medium', 'hard'] as $difficulty) {
            $row = $quiz->firstWhere('difficulty', $difficulty);

            $answered = (int) DB::table('fellowship_session_questions as sq')
                ->join('fellowship_quiz_sessions as s', 's.id', '=', 'sq.fellowship_quiz_session_id')
                ->where('s.user_id', $student->getKey())
                ->where('s.fellowship_id', $id)
                ->whereNotNull('sq.answered_at')
                ->where('sq.difficulty', $difficulty)
                ->count();

            $correct = (int) DB::table('fellowship_session_questions as sq')
                ->join('fellowship_quiz_sessions as s', 's.id', '=', 'sq.fellowship_quiz_session_id')
                ->where('s.user_id', $student->getKey())
                ->where('s.fellowship_id', $id)
                ->whereNotNull('sq.answered_at')
                ->where('sq.difficulty', $difficulty)
                ->where('sq.is_correct', true)
                ->count();

            $ledger->same('Quiz performance counts '.$difficulty.' answers', $answered, $row['answered_questions'] ?? null, $dashboard);
            $ledger->same('Quiz performance counts '.$difficulty.' correct', $correct, $row['correct_answers'] ?? null, $dashboard);
            $ledger->sameNumber(
                'Quiz performance scores '.$difficulty,
                $answered === 0 ? null : round($correct / $answered * 100, 2),
                $row['average_score'] ?? null,
                $dashboard,
            );
        }

        // The topic rows must sum to the overall answer count.
        $ledger->same(
            'The topic breakdown accounts for every confirmed answer',
            $this->confirmedAnswers($context),
            (int) $reported->sum('answered_questions'),
            $dashboard,
        );

        $ledger->same(
            'The difficulty breakdown accounts for every confirmed answer',
            $this->confirmedAnswers($context),
            (int) $quiz->sum('answered_questions'),
            $dashboard,
        );
    }

    // ── Snapshot immutability ─────────────────────────────────────────────────

    /**
     * Rewrite a question every way an admin can, and check that nothing a
     * session already recorded moves.
     */
    private function snapshotsSurviveEditing(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Snapshots · Editing a live question');

        $sessionId = $context->sessions['full'] ?? null;

        if ($sessionId === null) {
            $ledger->skip('Editing a live question leaves history alone', 'No completed session.');

            return;
        }

        $before = $context->api->get('/api/student/fellowship-sessions/'.$sessionId);
        $beforeResult = $context->api->get('/api/student/fellowship-sessions/'.$sessionId.'/result');
        $beforeDashboard = $context->api->get('/api/student/fellowships/'.$context->fellowship?->getKey());

        $questionIds = DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->whereNotNull('fellowship_question_id')
            ->pluck('fellowship_question_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($questionIds === []) {
            $ledger->skip('Editing a live question leaves history alone', 'The session traces back to no live question.');

            return;
        }

        $edited = 0;

        foreach ($questionIds as $questionId) {
            $question = FellowshipQuestion::query()->find($questionId);

            if ($question === null) {
                continue;
            }

            // Everything a session renders and scores from, rewritten at once.
            $question->update([
                'question_text' => FellowshipSmokeFixtures::MARKER.'REWORDED after the session: '.$question->question_text,
                'explanation' => FellowshipSmokeFixtures::MARKER.'REWRITTEN explanation.',
                'difficulty' => $question->difficulty?->value === 'hard' ? 'easy' : 'hard',
            ]);

            // Replace the option set and move the correct answer onto a new one.
            $question->options()->delete();

            foreach (['Rewritten option one', 'Rewritten option two', 'Rewritten option three'] as $index => $text) {
                $question->options()->create([
                    'option_text' => FellowshipSmokeFixtures::MARKER.$text,
                    'is_correct' => $index === 2,
                    'order' => $index + 1,
                ]);
            }

            $edited++;
        }

        $ledger->check('Every live question behind the session was rewritten', $edited === count($questionIds), 'Rewrote '.$edited.' of '.count($questionIds).'.');

        $after = $context->api->get('/api/student/fellowship-sessions/'.$sessionId);
        $afterResult = $context->api->get('/api/student/fellowship-sessions/'.$sessionId.'/result');
        $afterDashboard = $context->api->get('/api/student/fellowships/'.$context->fellowship?->getKey());

        $ledger->same('A rewritten question does not change what the session shows', $before->raw, $after->raw, $after);
        $ledger->same('A rewritten question does not change the session result', $beforeResult->raw, $afterResult->raw, $afterResult);
        $ledger->same('A rewritten question does not change the analytics', $beforeDashboard->raw, $afterDashboard->raw, $afterDashboard);

        // Spelled out, so a failure says which property moved.
        $beforeQuestions = collect($before->json('data.questions', []))->keyBy('id');
        $afterQuestions = collect($after->json('data.questions', []))->keyBy('id');

        foreach ($beforeQuestions as $id => $original) {
            $now = $afterQuestions[$id] ?? [];

            $ledger->same('Session question '.$id.' keeps its wording', $original['question_text'] ?? null, $now['question_text'] ?? null);
            $ledger->same('Session question '.$id.' keeps its options', $original['options'] ?? null, $now['options'] ?? null);
            $ledger->same('Session question '.$id.' keeps its correct option', $original['correct_option_key'] ?? null, $now['correct_option_key'] ?? null);
            $ledger->same('Session question '.$id.' keeps its verdict', $original['is_correct'] ?? null, $now['is_correct'] ?? null);
            $ledger->same('Session question '.$id.' keeps its explanation', $original['explanation'] ?? null, $now['explanation'] ?? null);
            $ledger->same('Session question '.$id.' keeps its difficulty', $original['difficulty'] ?? null, $now['difficulty'] ?? null);
        }
    }

    /**
     * Re-filing a question under a different Performance Topic changes what
     * future sessions capture, and nothing already recorded.
     */
    private function snapshotsSurviveTopicReassignment(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Snapshots · Topic reassignment');

        $fellowship = $context->fellowship;
        $sessionId = $context->sessions['full'] ?? null;

        if ($fellowship === null || $sessionId === null) {
            $ledger->skip('Topic reassignment leaves history alone', 'No Fellowship or completed session.');

            return;
        }

        $performanceFilter = $fellowship->refresh()->performanceFilter;

        if ($performanceFilter === null) {
            $ledger->skip('Topic reassignment leaves history alone', 'The Fellowship has no Performance Topic filter.');

            return;
        }

        $row = DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->whereNotNull('fellowship_question_id')
            ->whereNotNull('performance_filter_value_id')
            ->first();

        if ($row === null) {
            $ledger->skip('Topic reassignment leaves history alone', 'No session question carries a topic.');

            return;
        }

        $newTopic = FellowshipFilterValue::query()
            ->where('fellowship_filter_id', $performanceFilter->getKey())
            ->where('id', '<>', $row->performance_filter_value_id)
            ->first();

        if ($newTopic === null) {
            $ledger->skip('Topic reassignment leaves history alone', 'The Fellowship has only one topic value.');

            return;
        }

        $beforeTopics = $context->api->get('/api/student/fellowships/'.$fellowship->getKey())->json('data.topic_performance');

        FellowshipQuestion::query()
            ->whereKey($row->fellowship_question_id)
            ->update(['performance_filter_value_id' => $newTopic->getKey()]);

        $ledger->same(
            'The session question keeps the topic id it captured',
            (int) $row->performance_filter_value_id,
            (int) DB::table('fellowship_session_questions')->where('id', $row->id)->value('performance_filter_value_id'),
        );

        $ledger->same(
            'The session question keeps the topic name it captured',
            $row->topic_value,
            DB::table('fellowship_session_questions')->where('id', $row->id)->value('topic_value'),
        );

        $afterTopics = $context->api->get('/api/student/fellowships/'.$fellowship->getKey())->json('data.topic_performance');

        $ledger->same('Topic performance is unchanged by a reassignment', $beforeTopics, $afterTopics);

        // A new session, however, must capture the new topic.
        $created = $context->api->post('/api/student/fellowships/'.$fellowship->getKey().'/sessions', [
            'title' => FellowshipSmokeFixtures::MARKER.'After reassignment',
            'question_count' => 1,
            'filters' => [$performanceFilter->getKey() => [$newTopic->getKey()]],
        ]);

        if ($created->status === 201) {
            $context->sessions['reassigned'] = (int) $created->json('data.id');

            $ledger->same(
                'A session generated afterwards captures the new topic',
                $newTopic->value,
                $created->json('data.questions.0.performance_topic.value'),
                $created,
            );
        } else {
            $ledger->skip('A session generated afterwards captures the new topic', 'Could not generate a session on the new topic.');
        }
    }

    /**
     * A topic deleted and created again under the same name is a second
     * identity, and history must keep the two apart.
     */
    private function recreatedTopicStaysHistoricallyDistinct(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Snapshots · Recreated topic');

        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            return;
        }

        $performanceFilter = $fellowship->refresh()->performanceFilter;

        if ($performanceFilter === null) {
            $ledger->skip('A recreated topic stays historically distinct', 'No Performance Topic filter.');

            return;
        }

        $filters = app(FellowshipFilterService::class);
        $topicName = FellowshipSmokeFixtures::MARKER.'TEMP EXAM';

        // A topic of its own, so nothing else is answered under it.
        $firstIdentity = $filters->resolveValue($performanceFilter, $topicName);

        $question = FellowshipQuestion::query()
            ->forFellowship($fellowship->getKey())
            ->whereHas('options', fn ($options) => $options->where('is_correct', true))
            ->has('options', '>=', 2)
            ->first();

        if ($question === null) {
            $ledger->skip('A recreated topic stays historically distinct', 'No answerable question to re-file.');

            return;
        }

        $originalTopicId = $question->performance_filter_value_id;

        $question->update(['performance_filter_value_id' => $firstIdentity->getKey()]);

        // Answer it under the first identity.
        $firstSession = $this->answerOneQuestionUnderTopic($context, $performanceFilter->getKey(), $firstIdentity->getKey(), 'temp topic, first identity');

        if ($firstSession === null) {
            $question->update(['performance_filter_value_id' => $originalTopicId]);
            $ledger->skip('A recreated topic stays historically distinct', 'Could not answer a question under the first identity.');

            return;
        }

        // Delete that topic and create it again under the same name. The
        // question has to come off it first: a question's topic is a RESTRICT
        // reference, which is what stops a topic in use being deleted.
        $question->update(['performance_filter_value_id' => $originalTopicId]);
        FellowshipFilterValue::query()->whereKey($firstIdentity->getKey())->delete();

        $ledger->check(
            'The first topic identity was deleted',
            ! FellowshipFilterValue::query()->whereKey($firstIdentity->getKey())->exists(),
            'The topic value still exists.',
        );

        $secondIdentity = $filters->resolveValue($performanceFilter, $topicName);

        $ledger->check(
            'Recreating the topic produces a new identity',
            $secondIdentity->getKey() !== $firstIdentity->getKey(),
            'The recreated topic reused the old id.',
        );

        $question->update(['performance_filter_value_id' => $secondIdentity->getKey()]);

        $secondSession = $this->answerOneQuestionUnderTopic($context, $performanceFilter->getKey(), $secondIdentity->getKey(), 'temp topic, second identity');

        $question->update(['performance_filter_value_id' => $originalTopicId]);

        if ($secondSession === null) {
            $ledger->skip('A recreated topic is reported separately', 'Could not answer a question under the second identity.');

            return;
        }

        $topics = collect($context->api->get('/api/student/fellowships/'.$fellowship->getKey())->json('data.topic_performance', []));

        $sameName = $topics->filter(fn (array $row): bool => ($row['topic_value'] ?? null) === $topicName)->values();

        $ledger->same(
            'The two identities of one topic name are reported separately',
            2,
            $sameName->count(),
        );

        $ledger->check(
            'The deleted topic still reads correctly in history',
            $sameName->contains(fn (array $row): bool => (int) ($row['topic_value_id'] ?? 0) === $firstIdentity->getKey()),
            'History lost the answer recorded under the deleted topic id.',
        );

        $ledger->check(
            'The recreated topic is reported under its own id',
            $sameName->contains(fn (array $row): bool => (int) ($row['topic_value_id'] ?? 0) === $secondIdentity->getKey()),
            'The recreated topic id is absent from the breakdown.',
        );

        $ledger->note('Recreated topic ids', $firstIdentity->getKey().' then '.$secondIdentity->getKey());
    }

    /**
     * A question force-deleted after a session must leave the session intact,
     * with only its traceability link gone.
     */
    private function snapshotsSurviveDeletion(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Snapshots · Deleting a live question');

        $fellowship = $context->fellowship;
        $sessionId = $context->sessions['full'] ?? null;

        if ($fellowship === null || $sessionId === null) {
            $ledger->skip('Deleting a live question leaves history alone', 'No Fellowship or completed session.');

            return;
        }

        $row = DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->whereNotNull('fellowship_question_id')
            ->first();

        if ($row === null) {
            $ledger->skip('Deleting a live question leaves history alone', 'No session question traces to a live one.');

            return;
        }

        $before = $context->api->get('/api/student/fellowship-sessions/'.$sessionId);
        $beforeDashboard = $context->api->get('/api/student/fellowships/'.$fellowship->getKey());
        $beforeScore = $beforeDashboard->json('data.average_score');
        $beforeTopics = $beforeDashboard->json('data.topic_performance');

        // Soft delete first, which is what the admin panel does.
        $question = FellowshipQuestion::query()->find($row->fellowship_question_id);
        $question?->delete();

        $afterSoftDelete = $context->api->get('/api/student/fellowship-sessions/'.$sessionId);

        $ledger->same('A soft-deleted question leaves the session unchanged', $before->raw, $afterSoftDelete->raw, $afterSoftDelete);

        $ledger->same(
            'A soft-deleted question is no longer drawn into new sessions',
            0,
            (int) DB::table('fellowship_questions')->where('id', $row->fellowship_question_id)->whereNull('deleted_at')->count(),
        );

        // Then force delete, which cuts the traceability link.
        FellowshipQuestion::withTrashed()->whereKey($row->fellowship_question_id)->forceDelete();
        $context->forceDeletedQuestions++;

        $ledger->check(
            'The question row is gone',
            ! DB::table('fellowship_questions')->where('id', $row->fellowship_question_id)->exists(),
            'The question row survived a force delete.',
        );

        $ledger->same(
            'The session question loses only its link to the deleted question',
            null,
            DB::table('fellowship_session_questions')->where('id', $row->id)->value('fellowship_question_id'),
        );

        $afterDelete = $context->api->get('/api/student/fellowship-sessions/'.$sessionId);

        $ledger->same(
            'A deleted question still renders from the snapshot',
            $before->json('data.questions.0.question_text'),
            $afterDelete->json('data.questions.0.question_text'),
            $afterDelete,
        );

        $ledger->same(
            'A deleted question keeps its recorded verdict',
            $before->json('data.correct_answers'),
            $afterDelete->json('data.correct_answers'),
            $afterDelete,
        );

        $ledger->same(
            'A deleted question keeps the session score',
            $before->json('data.average_score'),
            $afterDelete->json('data.average_score'),
            $afterDelete,
        );

        $afterDashboard = $context->api->get('/api/student/fellowships/'.$fellowship->getKey());

        $ledger->sameNumber(
            'Deleting a question does not move the average score',
            is_numeric($beforeScore) ? (float) $beforeScore : null,
            $afterDashboard->json('data.average_score'),
            $afterDashboard,
        );

        $ledger->same(
            'Deleting a question does not move the topic breakdown',
            $beforeTopics,
            $afterDashboard->json('data.topic_performance'),
            $afterDashboard,
        );

        $result = $context->api->get('/api/student/fellowship-sessions/'.$sessionId.'/result');

        $ledger->same('The result of a session with a deleted question is still readable', 200, $result->status, $result);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Move the clock to one instant, expressed in UTC.
     *
     * The conversion is the point. `app.timezone` is UTC, so in production
     * `now()` is always a UTC instant; handing the clock a Carbon in another
     * zone makes Eloquent persist that zone's wall-clock reading while the
     * in-memory attribute keeps its offset, and a duration computed across the
     * two then differs from the stored timestamps by the offset. Travelling in
     * UTC keeps the fake clock behaving exactly as the real one does, while the
     * caller still expresses the instant in whichever zone reads clearly.
     */
    private function travelTo(Carbon $instant): void
    {
        Carbon::setTestNow($instant->copy()->utc());
    }

    /**
     * Generate a one-question session restricted to a topic, answer it, and
     * return the session id.
     */
    private function answerOneQuestionUnderTopic(
        FellowshipSmokeContext $context,
        int $filterId,
        int $valueId,
        string $label,
    ): ?int {
        $created = $context->api->post('/api/student/fellowships/'.$context->fellowship?->getKey().'/sessions', [
            'title' => FellowshipSmokeFixtures::MARKER.$label,
            'question_count' => 1,
            'filters' => [$filterId => [$valueId]],
        ]);

        if ($created->status !== 201) {
            return null;
        }

        $sessionId = (int) $created->json('data.id');
        $question = $created->json('data.questions.0', []);

        $context->api->post(
            '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$question['id'].'/answer',
            ['option_key' => (string) $this->correctKeyFor((int) $question['id'])],
        );

        return $sessionId;
    }

    /**
     * correct confirmed answers / all confirmed answers * 100, computed here.
     */
    private function expectedAverageScore(FellowshipSmokeContext $context): int|float|null
    {
        $total = $this->confirmedAnswers($context);

        if ($total === 0) {
            return null;
        }

        return round($this->confirmedCorrect($context) / $total * 100, 2);
    }

    private function expectedSessionScore(int $sessionId): int|float|null
    {
        $answered = (int) DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->whereNotNull('answered_at')
            ->count();

        if ($answered === 0) {
            return null;
        }

        $correct = (int) DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->whereNotNull('answered_at')
            ->where('is_correct', true)
            ->count();

        return round($correct / $answered * 100, 2);
    }

    private function confirmedAnswers(FellowshipSmokeContext $context): int
    {
        return (int) $this->confirmedQuery($context)->count();
    }

    private function confirmedCorrect(FellowshipSmokeContext $context): int
    {
        return (int) $this->confirmedQuery($context)->where('sq.is_correct', true)->count();
    }

    /**
     * @return Builder
     */
    private function confirmedQuery(FellowshipSmokeContext $context)
    {
        return DB::table('fellowship_session_questions as sq')
            ->join('fellowship_quiz_sessions as s', 's.id', '=', 'sq.fellowship_quiz_session_id')
            ->where('s.user_id', $context->student?->getKey())
            ->where('s.fellowship_id', $context->fellowship?->getKey())
            ->whereNotNull('sq.answered_at');
    }

    private function correctKeyFor(int $sessionQuestionId): ?string
    {
        $row = DB::table('fellowship_session_questions')->where('id', $sessionQuestionId)->first();

        if ($row === null) {
            return null;
        }

        $snapshot = json_decode((string) $row->question_snapshot, associative: true);

        foreach ($snapshot['options'] ?? [] as $option) {
            if (($option['is_correct'] ?? false) === true) {
                return (string) $option['key'];
            }
        }

        return null;
    }
}
