<?php

namespace App\Services\Fellowship;

use App\Enums\QuizDifficulty;
use App\Models\Fellowship;
use App\Models\FellowshipQuizSession;
use App\Models\FellowshipSessionPeriod;
use App\Models\FellowshipSessionQuestion;
use App\Models\User;
use App\Support\Fellowship\FellowshipClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the student's Fellowship and session screens report.
 *
 * Two rules run through all of it.
 *
 * The first is what counts as an answer. Only a *confirmed* answer does, and
 * every confirmed answer counts the moment it is given — including answers in a
 * session the student has paused and not finished. A question answered again in
 * a later session is a second answer, not a correction of the first: repeated
 * attempts each count. Nothing here ever uses the size of the question bank as a
 * denominator, which would make the figure a coverage measure rather than a
 * score.
 *
 * The second is where a topic comes from. Always the session question's own
 * captured topic, never the live question's — so re-filing a question under a
 * different Performance Topic leaves the history that was already recorded
 * under the old one alone.
 *
 * `average_score` is the one figure these screens share. The frontend shows it
 * as "Completion Rate" on the Fellowship list and as "Average Score" inside a
 * Fellowship or a session; it is the same number computed the same way.
 */
class FellowshipAnalyticsService
{
    /**
     * Every confirmed answer this student has given in this Fellowship, across
     * all of their sessions.
     *
     * @return Builder<FellowshipSessionQuestion>
     */
    public function confirmedAnswers(User $student, Fellowship $fellowship): Builder
    {
        return FellowshipSessionQuestion::query()
            ->answered()
            ->whereHas(
                'session',
                fn (Builder $session): Builder => $session
                    ->where('user_id', $student->getKey())
                    ->where('fellowship_id', $fellowship->getKey()),
            );
    }

    /**
     * correct confirmed answers / all confirmed answers * 100.
     *
     * Null when the student has answered nothing: zero would claim they got
     * everything wrong.
     */
    public function averageScore(User $student, Fellowship $fellowship): ?float
    {
        $answers = $this->confirmedAnswers($student, $fellowship);

        $total = $answers->clone()->count();

        if ($total === 0) {
            return null;
        }

        return $this->percentage($answers->clone()->where('is_correct', true)->count(), $total);
    }

    /**
     * The same formula over one session only.
     */
    public function sessionAverageScore(FellowshipQuizSession $session): ?float
    {
        $total = $session->questions()->answered()->count();

        if ($total === 0) {
            return null;
        }

        return $this->percentage($session->questions()->answered()->where('is_correct', true)->count(), $total);
    }

    /**
     * @return array{total_questions: int, answered_questions: int, correct_answers: int, incorrect_answers: int, average_score: float|null}
     */
    public function sessionTally(FellowshipQuizSession $session): array
    {
        $answered = $session->questions()->answered()->count();
        $correct = $session->questions()->answered()->where('is_correct', true)->count();

        return [
            'total_questions' => $session->questions()->count(),
            'answered_questions' => $answered,
            'correct_answers' => $correct,
            'incorrect_answers' => $answered - $correct,
            'average_score' => $answered === 0 ? null : $this->percentage($correct, $answered),
        ];
    }

    /**
     * How many distinct Performance Topics the student has answered at least one
     * question in. Not how many they have exhausted — a single confirmed answer
     * puts a topic in.
     */
    public function topicsCompleted(User $student, Fellowship $fellowship): int
    {
        // Derived from the same grouping Topic Performance reports, so the two
        // can never disagree about how many topics the student has touched —
        // and so this cannot be thrown off by a live topic row being deleted,
        // which the grouping does not depend on.
        return count(array_filter(
            $this->topicPerformance($student, $fellowship),
            static fn (array $topic): bool => $topic['topic_value_id'] !== null || $topic['topic_value'] !== null,
        ));
    }

    /**
     * Per-topic accuracy across every session: correct confirmed answers in the
     * topic over all confirmed answers in it.
     *
     * Grouped by the topic captured in the session question, with the display
     * text captured alongside it, so a renamed or deleted topic value still
     * reads correctly in history.
     *
     * @return list<array{topic_value_id: int|null, topic_value: string|null, answered_questions: int, correct_answers: int, incorrect_answers: int, average_score: float|null}>
     */
    public function topicPerformance(User $student, Fellowship $fellowship): array
    {
        return $this->topicRows($this->confirmedAnswers($student, $fellowship));
    }

    /**
     * The same breakdown scoped to one session, for its result screen.
     *
     * @return list<array{topic_value_id: int|null, topic_value: string|null, answered_questions: int, correct_answers: int, incorrect_answers: int, average_score: float|null}>
     */
    public function sessionTopicPerformance(FellowshipQuizSession $session): array
    {
        return $this->topicRows($session->questions()->answered()->getQuery());
    }

    /**
     * The distinct Performance Topics a session's questions cover, for the
     * session history row.
     *
     * @return list<string>
     */
    public function sessionTopics(FellowshipQuizSession $session): array
    {
        return $session->questions()
            ->whereNotNull('topic_value')
            // `questions()` orders by position, which a DISTINCT over
            // `topic_value` alone cannot carry: MySQL under ONLY_FULL_GROUP_BY
            // rejects an ORDER BY on a column outside the select list. The
            // distinct topics are ordered by their own value instead.
            ->reorder()
            ->distinct()
            ->orderBy('topic_value')
            ->pluck('topic_value')
            ->map(static fn ($value): string => (string) $value)
            ->all();
    }

    /**
     * Per-difficulty accuracy across the Fellowship — the Quiz Performance
     * panel.
     *
     * Every level is reported, including those the student has not touched, so
     * the panel's shape does not change as they progress.
     *
     * @return list<array{difficulty: string, answered_questions: int, correct_answers: int, incorrect_answers: int, average_score: float|null}>
     */
    public function quizPerformance(User $student, Fellowship $fellowship): array
    {
        $rows = $this->confirmedAnswers($student, $fellowship)
            ->selectRaw('difficulty, count(*) as answered, sum(case when is_correct = 1 then 1 else 0 end) as correct')
            ->groupBy('difficulty')
            ->get()
            ->keyBy('difficulty');

        return array_map(function (QuizDifficulty $difficulty) use ($rows): array {
            $row = $rows->get($difficulty->value);
            $answered = (int) ($row->answered ?? 0);
            $correct = (int) ($row->correct ?? 0);

            return [
                'difficulty' => $difficulty->value,
                'answered_questions' => $answered,
                'correct_answers' => $correct,
                'incorrect_answers' => $answered - $correct,
                'average_score' => $answered === 0 ? null : $this->percentage($correct, $answered),
            ];
        }, QuizDifficulty::cases());
    }

    /**
     * Total study time on this Fellowship, in seconds.
     *
     * Summed from activity periods, not from how long the sessions have existed:
     * a session paused overnight must not bill the night. A period still open
     * counts up to this request, so the figure moves while the student works.
     */
    public function studyTimeSeconds(User $student, Fellowship $fellowship, ?Carbon $asOf = null): int
    {
        $asOf ??= now();

        return (int) $this->periods($student, $fellowship, $asOf)
            ->sum(fn (array $period): int => max(0, (int) $period['start']->diffInSeconds($period['end'], absolute: true)));
    }

    public function sessionStudyTimeSeconds(FellowshipQuizSession $session, ?Carbon $asOf = null): int
    {
        $asOf ??= now();

        return (int) $session->periods()->get()->sum(
            fn ($period): int => $period->durationSeconds($asOf)
        );
    }

    /**
     * Study time per calendar day, for the Daily Study Time chart.
     *
     * A period that runs past midnight is split across the days it covers, in
     * the module's configured timezone, so an evening session does not land
     * entirely on the day it began.
     *
     * @return list<array{date: string, seconds: int}>
     */
    public function dailyStudyTime(User $student, Fellowship $fellowship, int $days = 7, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $days = max(1, $days);

        $totals = [];

        foreach ($this->periods($student, $fellowship, $asOf) as $period) {
            foreach (FellowshipClock::splitAcrossDays($period['start'], $period['end']) as $date => $seconds) {
                $totals[$date] = ($totals[$date] ?? 0) + $seconds;
            }
        }

        // A fixed window of days, so a quiet day is a zero rather than a gap.
        $series = [];
        $cursor = FellowshipClock::local($asOf)->startOfDay()->subDays($days - 1);

        for ($index = 0; $index < $days; $index++) {
            $date = $cursor->toDateString();

            $series[] = ['date' => $date, 'seconds' => (int) ($totals[$date] ?? 0)];

            $cursor = $cursor->addDay();
        }

        return $series;
    }

    /**
     * Consecutive days, up to today, on which the student confirmed at least one
     * answer in this Fellowship.
     *
     * Days come from confirmed answers, never from how long a session was left
     * open: a session paused for a week is not a week's streak. Counting starts
     * at today, or at yesterday when today has no answer yet, so a streak is not
     * reported as broken before the day is over.
     */
    public function dayStreak(User $student, Fellowship $fellowship, ?Carbon $asOf = null): int
    {
        $asOf ??= now();

        $days = $this->activeDays($student, $fellowship);

        if ($days === []) {
            return 0;
        }

        $today = FellowshipClock::local($asOf)->startOfDay();
        $cursor = isset($days[$today->toDateString()])
            ? $today
            : $today->copy()->subDay();

        if (! isset($days[$cursor->toDateString()])) {
            return 0;
        }

        $streak = 0;

        while (isset($days[$cursor->toDateString()])) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    /**
     * The student's recent confirmed answers in this Fellowship, newest first —
     * the Recent Activity list.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, FellowshipSessionQuestion>
     */
    public function recentActivity(User $student, Fellowship $fellowship, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return $this->confirmedAnswers($student, $fellowship)
            ->with('session')
            ->orderByDesc('answered_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * Calendar days with at least one confirmed answer, as a lookup keyed by
     * `Y-m-d` in the configured timezone.
     *
     * Bucketed in PHP rather than with a database date function: the timezone
     * conversion has to agree with the rest of the module, and date handling is
     * the one thing MySQL and SQLite disagree about most readily.
     *
     * @return array<string, true>
     */
    private function activeDays(User $student, Fellowship $fellowship): array
    {
        return $this->confirmedAnswers($student, $fellowship)
            ->orderBy('answered_at')
            ->pluck('answered_at')
            ->reduce(function (array $days, $answeredAt): array {
                if ($answeredAt !== null) {
                    $days[FellowshipClock::dateOf($answeredAt)] = true;
                }

                return $days;
            }, []);
    }

    /**
     * Every activity period of this student's sessions on this Fellowship,
     * resolved to a start and an end — an open period ending at the moment of
     * the request.
     *
     * @return Collection<int, array{start: Carbon, end: Carbon}>
     */
    private function periods(User $student, Fellowship $fellowship, ?Carbon $asOf = null): Collection
    {
        $asOf ??= now();

        return FellowshipSessionPeriod::query()
            ->whereHas(
                'session',
                fn (Builder $session): Builder => $session
                    ->where('user_id', $student->getKey())
                    ->where('fellowship_id', $fellowship->getKey()),
            )
            ->orderBy('started_at')
            ->get()
            ->map(fn ($period): array => [
                'start' => $period->started_at->copy()->utc(),
                'end' => ($period->ended_at ?? $asOf)->copy()->utc(),
            ]);
    }

    /**
     * Group confirmed answers by the topic they were *captured* under.
     *
     * The key is the pair (captured id, captured name), both read from the
     * session question's own columns and never from the live filter value. Three
     * consequences, all of them intended:
     *
     *  - deleting the live topic leaves this grouping untouched, because nothing
     *    here follows a foreign key;
     *  - renaming a topic splits it, so answers given under the old name stay
     *    reported under it;
     *  - a topic deleted and recreated under the same display name is a second
     *    identity, reported separately rather than folded into the first.
     *
     * @param  Builder<FellowshipSessionQuestion>  $answers
     * @return list<array{topic_value_id: int|null, topic_value: string|null, answered_questions: int, correct_answers: int, incorrect_answers: int, average_score: float|null}>
     */
    private function topicRows(Builder $answers): array
    {
        return $answers
            // A session's questions arrive ordered by position, which this
            // grouping has no business carrying: MySQL under ONLY_FULL_GROUP_BY
            // rejects an ORDER BY on a column that is neither grouped nor
            // aggregated. Dropped here rather than at each call site, since it
            // is the grouping that makes the inherited order invalid.
            ->reorder()
            ->selectRaw('performance_filter_value_id, topic_value, count(*) as answered, sum(case when is_correct = 1 then 1 else 0 end) as correct')
            ->groupBy('performance_filter_value_id', 'topic_value')
            ->orderBy('topic_value')
            ->get()
            ->map(function ($row): array {
                $answered = (int) $row->answered;
                $correct = (int) $row->correct;

                return [
                    'topic_value_id' => $row->performance_filter_value_id !== null
                        ? (int) $row->performance_filter_value_id
                        : null,
                    'topic_value' => $row->topic_value !== null ? (string) $row->topic_value : null,
                    'answered_questions' => $answered,
                    'correct_answers' => $correct,
                    'incorrect_answers' => $answered - $correct,
                    'average_score' => $answered === 0 ? null : $this->percentage($correct, $answered),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Rounded to two decimal places: enough to distinguish 66.67 from 66.66
     * without pretending to more precision than a handful of answers supports.
     */
    private function percentage(int $part, int $whole): float
    {
        if ($whole === 0) {
            return 0.0;
        }

        return round($part / $whole * 100, 2);
    }
}
