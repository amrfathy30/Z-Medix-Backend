<?php

namespace App\Support\Smoke\Fellowship\Scenarios;

use App\Enums\FellowshipSessionStatus;
use App\Filament\Pages\FellowshipSettingsPage;
use App\Models\FellowshipQuizSession;
use App\Support\Fellowship\FellowshipSettings;
use App\Support\Smoke\Fellowship\FellowshipSmokeContext;
use App\Support\Smoke\Fellowship\FellowshipSmokeFixtures;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The advisory per-question timer, and the only two things that stop a session's
 * clock.
 *
 * Timing is informational throughout: a question whose limit has passed is still
 * answered, still graded, and still counted. What the timer produces is one
 * extra fact per answer — whether it arrived inside the limit — and that fact
 * must never reach a score.
 *
 * Time is moved with a controlled clock rather than by waiting, because the
 * behaviour under test is what gets *written*: the server computes the verdict
 * and the elapsed seconds from its own clock, so travelling that clock and then
 * reading MySQL tests the same code a real wait would, in a fraction of the
 * time. The clock is always put back.
 */
class FellowshipTimerScenario
{
    /** The limit this scenario sets, through the admin settings page. */
    private const LIMIT_SECONDS = 45;

    public function run(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;

        if ($context->fellowship === null || ! $context->asStudent()) {
            $ledger->skip('Timer behaviour', 'No Fellowship or authenticated student.');

            return;
        }

        try {
            $this->setGlobalLimit($context);
            $this->timedSessionSnapshotsTheLimit($context);
            $this->overtimeAnswerIsStillGraded($context);
            $this->untimedSessionHasNoVerdict($context);
            $this->pauseAndResume($context);
            $this->closingTheBrowserDoesNotStopTheClock($context);
        } finally {
            // The clock only ever moved forward during this scenario, so any
            // session still open has an activity period that began in the
            // future relative to real time. Pausing them while the travelled
            // clock is still in effect closes those periods with the durations
            // the scenario actually produced; putting the clock back first
            // would leave a period whose start is ahead of `now()`.
            $this->closeTravelledSessions($context);

            Carbon::setTestNow();
        }
    }

    // ── The global setting ────────────────────────────────────────────────────

    /**
     * The limit is platform-wide and edited by an admin, so it is set through
     * the settings page rather than by writing the row.
     */
    private function setGlobalLimit(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Timer · Global setting');

        $admin = $context->admin;

        if ($admin === null) {
            $ledger->skip('Question time limit set through the admin page', 'No admin available.');

            return;
        }

        Auth::guard('admin')->login($admin);
        Filament::setCurrentPanel('admin');

        $ledger->attempt('Question time limit set through the admin page', function (): void {
            Livewire::test(FellowshipSettingsPage::class)
                ->set('data.question_time_limit_seconds', self::LIMIT_SECONDS)
                ->call('save');
        });

        $ledger->same(
            'The saved limit is what the module now reports',
            self::LIMIT_SECONDS,
            FellowshipSettings::questionTimeLimitSeconds(),
        );

        $ledger->check(
            'The limit is stored as a non-public setting row',
            (bool) DB::table('settings')
                ->where('key', FellowshipSettings::QUESTION_TIME_LIMIT_KEY)
                ->where('is_public', false)
                ->exists(),
            'No private settings row holds the question time limit.',
        );

        // The student's own form must see the same number.
        $filters = $context->api->get('/api/student/fellowships/'.$context->fellowship?->getKey().'/filters');

        $ledger->same(
            'The student form is offered the same limit',
            self::LIMIT_SECONDS,
            $filters->json('data.question_time_limit_seconds'),
            $filters,
        );

        $ledger->note('Question time limit (seconds)', self::LIMIT_SECONDS);
    }

    // ── A timed session ───────────────────────────────────────────────────────

    /**
     * A timed session freezes the limit it was generated under, so changing the
     * platform setting later cannot re-judge a session already running.
     */
    private function timedSessionSnapshotsTheLimit(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Timer · Timed session');

        $session = $this->generate($context, 'Timed', questionCount: 3, timerEnabled: true);

        if ($session === null) {
            return;
        }

        [$sessionId, $questions] = $session;
        $context->sessions['timed'] = $sessionId;
        $ledger->note('Timed session ID', $sessionId);

        $ledger->same(
            'A timed session reports the timer on',
            true,
            DB::table('fellowship_quiz_sessions')->where('id', $sessionId)->value('timer_enabled') == 1,
        );

        $ledger->same(
            'A timed session snapshots the limit it was generated under',
            self::LIMIT_SECONDS,
            (int) DB::table('fellowship_quiz_sessions')->where('id', $sessionId)->value('question_time_limit_seconds'),
        );

        // The first question's clock starts with the session.
        $first = $questions[0];

        $ledger->check(
            'The first question\'s clock starts with the session',
            DB::table('fellowship_session_questions')->where('id', $first['id'])->value('activated_at') !== null,
            'activated_at was null on the question the student is on.',
        );

        $ledger->check(
            'Later questions have no clock running yet',
            DB::table('fellowship_session_questions')
                ->where('fellowship_quiz_session_id', $sessionId)
                ->where('position', '>', 1)
                ->whereNotNull('activated_at')
                ->count() === 0,
            'A question the student has not reached already had its clock started.',
        );

        // An answer inside the limit.
        $answered = $context->api->post(
            '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$first['id'].'/answer',
            ['option_key' => (string) $this->correctKeyFor((int) $first['id'])],
        );

        $ledger->same('An on-time answer is accepted', 200, $answered->status, $answered);
        $ledger->same(
            'An on-time answer is marked as answered within time',
            true,
            $answered->json('data.question.answered_within_time'),
            $answered,
        );
        $ledger->same(
            'The on-time verdict is persisted',
            1,
            (int) DB::table('fellowship_session_questions')->where('id', $first['id'])->value('answered_within_time'),
        );

        // Raising the platform limit must not change this session.
        FellowshipSettings::setQuestionTimeLimitSeconds(self::LIMIT_SECONDS * 4);

        $reread = $context->api->get('/api/student/fellowship-sessions/'.$sessionId);

        $ledger->same(
            'Raising the platform limit does not change a running session',
            self::LIMIT_SECONDS,
            $reread->json('data.question_time_limit_seconds'),
            $reread,
        );

        FellowshipSettings::setQuestionTimeLimitSeconds(self::LIMIT_SECONDS);

        // The next question's clock started the moment it became current.
        $second = $questions[1];

        $ledger->check(
            'The next question\'s clock starts when it becomes the current one',
            DB::table('fellowship_session_questions')->where('id', $second['id'])->value('activated_at') !== null,
            'activated_at was still null after the question became current.',
        );

        $ledger->check(
            'An answered question reports the time it took',
            (int) DB::table('fellowship_session_questions')->where('id', $first['id'])->value('active_seconds') >= 0,
            'active_seconds was not recorded.',
        );
    }

    /**
     * A question whose limit has passed is not auto-submitted, and the late
     * answer is graded exactly as an on-time one.
     */
    private function overtimeAnswerIsStillGraded(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Timer · Overtime');

        $sessionId = $context->sessions['timed'] ?? null;

        if ($sessionId === null) {
            $ledger->skip('An overtime answer is still graded', 'No timed session.');

            return;
        }

        $current = DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->whereNull('answered_at')
            ->orderBy('position')
            ->first();

        if ($current === null) {
            $ledger->skip('An overtime answer is still graded', 'The timed session has no unanswered question left.');

            return;
        }

        // Travel past the limit. The question is not submitted for the student
        // by doing so: expiry is advisory.
        $this->advanceSeconds(self::LIMIT_SECONDS + 30);

        $stillOpen = $context->api->get('/api/student/fellowship-sessions/'.$sessionId);

        $ledger->same(
            'A question whose time has passed is not auto-submitted',
            null,
            DB::table('fellowship_session_questions')->where('id', $current->id)->value('answered_at'),
        );

        $ledger->same(
            'A session whose question expired is still in progress',
            'in_progress',
            $stillOpen->json('data.status'),
            $stillOpen,
        );

        $elapsed = (int) ($stillOpen->json('data.questions.'.($current->position - 1).'.elapsed_seconds') ?? 0);

        $ledger->check(
            'The expired question reports elapsed time beyond its limit',
            $elapsed > self::LIMIT_SECONDS,
            sprintf('elapsed_seconds was %d, limit %d.', $elapsed, self::LIMIT_SECONDS),
            $stillOpen,
        );

        // Answer it correctly, late.
        $correctKey = (string) $this->correctKeyFor((int) $current->id);

        $late = $context->api->post(
            '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$current->id.'/answer',
            ['option_key' => $correctKey],
        );

        $ledger->same('An overtime answer is still accepted', 200, $late->status, $late);
        $ledger->same(
            'An overtime answer is marked as outside the time',
            false,
            $late->json('data.question.answered_within_time'),
            $late,
        );
        $ledger->same(
            'The overtime verdict is persisted as false, not null',
            0,
            (int) DB::table('fellowship_session_questions')->where('id', $current->id)->value('answered_within_time'),
        );
        $ledger->same(
            'An overtime answer is graded on its merits, not on its timing',
            true,
            $late->json('data.question.is_correct'),
            $late,
        );

        // Timing must not have touched the score.
        $tally = $context->api->get('/api/student/fellowship-sessions/'.$sessionId);

        $correct = (int) DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->where('is_correct', true)
            ->count();

        $answeredCount = (int) DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->whereNotNull('answered_at')
            ->count();

        $ledger->same(
            'The session score counts the overtime answer as correct',
            $correct,
            $tally->json('data.correct_answers'),
            $tally,
        );

        $ledger->sameNumber(
            'The session score is correct over answered, timing aside',
            round($correct / max(1, $answeredCount) * 100, 2),
            $tally->json('data.average_score'),
            $tally,
        );

        $overtimeAffectedScore = (int) DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->where('answered_within_time', false)
            ->where('is_correct', false)
            ->count();

        $ledger->same(
            'No answer was marked wrong merely for being late',
            0,
            $overtimeAffectedScore,
        );
    }

    /**
     * With timing off, the verdict is not applicable and must be null — not
     * false, which would read as "late".
     */
    private function untimedSessionHasNoVerdict(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Timer · Untimed session');

        $session = $this->generate($context, 'Untimed', questionCount: 2, timerEnabled: false);

        if ($session === null) {
            return;
        }

        [$sessionId, $questions] = $session;
        $context->sessions['untimed'] = $sessionId;

        $ledger->same(
            'An untimed session stores no limit',
            null,
            DB::table('fellowship_quiz_sessions')->where('id', $sessionId)->value('question_time_limit_seconds'),
        );

        $first = $questions[0];

        // Answer it long after any limit would have passed.
        $this->advanceSeconds(self::LIMIT_SECONDS * 10);

        $answered = $context->api->post(
            '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$first['id'].'/answer',
            ['option_key' => (string) $this->correctKeyFor((int) $first['id'])],
        );

        $ledger->same('An untimed answer is accepted however long it took', 200, $answered->status, $answered);
        $ledger->same(
            'An untimed answer has no timing verdict at all',
            null,
            $answered->json('data.question.answered_within_time'),
            $answered,
        );
        $ledger->same(
            'The absent verdict is stored as null rather than false',
            null,
            DB::table('fellowship_session_questions')->where('id', $first['id'])->value('answered_within_time'),
        );
        $ledger->same('An untimed answer is still graded', true, $answered->json('data.question.is_correct'), $answered);
    }

    // ── Pause and resume ──────────────────────────────────────────────────────

    /**
     * Pause is the only thing that stops the clock; resume starts it again on
     * the same question, with the time already spent preserved.
     */
    private function pauseAndResume(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Timer · Pause and resume');

        $session = $this->generate($context, 'Pause and resume', questionCount: 3, timerEnabled: true);

        if ($session === null) {
            return;
        }

        [$sessionId, $questions] = $session;
        $context->sessions['paused'] = $sessionId;
        $ledger->note('Pause/resume session ID', $sessionId);

        $ledger->same(
            'A new session opens exactly one activity period',
            1,
            (int) DB::table('fellowship_session_periods')->where('fellowship_quiz_session_id', $sessionId)->count(),
        );

        // Spend some time, then pause.
        $this->advanceSeconds(20);

        $paused = $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/pause');

        $ledger->same('A session in progress can be paused', 200, $paused->status, $paused);
        $ledger->same('A paused session says it is paused', 'paused', $paused->json('data.status'), $paused);

        $bankedStudyTime = (int) $paused->json('data.study_time_seconds');
        $bankedQuestionTime = (int) DB::table('fellowship_session_questions')
            ->where('id', $questions[0]['id'])
            ->value('active_seconds');

        $ledger->check(
            'Pausing banks the time already spent',
            $bankedStudyTime >= 20,
            sprintf('study_time_seconds was %d after 20 seconds of work.', $bankedStudyTime),
            $paused,
        );

        $ledger->check(
            'Pausing banks the current question\'s elapsed time',
            $bankedQuestionTime >= 20,
            sprintf('active_seconds was %d after 20 seconds on the question.', $bankedQuestionTime),
        );

        $ledger->same(
            'Pausing stops the current question\'s clock',
            null,
            DB::table('fellowship_session_questions')->where('id', $questions[0]['id'])->value('activated_at'),
        );

        $ledger->same(
            'Pausing closes the open activity period',
            0,
            (int) DB::table('fellowship_session_periods')
                ->where('fellowship_quiz_session_id', $sessionId)
                ->whereNull('ended_at')
                ->count(),
        );

        // While paused, time must not accumulate.
        $this->advanceSeconds(30 * 60);

        $whilePaused = $context->api->get('/api/student/fellowship-sessions/'.$sessionId);

        $ledger->same(
            'A paused session accumulates no study time',
            $bankedStudyTime,
            (int) $whilePaused->json('data.study_time_seconds'),
            $whilePaused,
        );

        $ledger->same(
            'A paused question accumulates no elapsed time',
            $bankedQuestionTime,
            (int) DB::table('fellowship_session_questions')->where('id', $questions[0]['id'])->value('active_seconds'),
        );

        // A second pause must be refused rather than opening anything.
        $again = $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/pause');

        $ledger->same('A paused session cannot be paused again (422)', 422, $again->status, $again);
        $ledger->same('The repeated pause says why', 'FELLOWSHIP_SESSION_ALREADY_PAUSED', $again->json('code'), $again);

        // An answer while paused must be refused: the session is not in progress.
        $whilePausedAnswer = $context->api->post(
            '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$questions[0]['id'].'/answer',
            ['option_key' => (string) $this->correctKeyFor((int) $questions[0]['id'])],
        );

        $ledger->same('A paused session cannot be answered (422)', 422, $whilePausedAnswer->status, $whilePausedAnswer);
        $ledger->same(
            'Answering a paused session says to resume first',
            'FELLOWSHIP_SESSION_NOT_IN_PROGRESS',
            $whilePausedAnswer->json('code'),
            $whilePausedAnswer,
        );

        // Resume: same question, time preserved.
        $resumed = $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/resume');

        $ledger->same('A paused session can be resumed', 200, $resumed->status, $resumed);
        $ledger->same('A resumed session is in progress again', 'in_progress', $resumed->json('data.status'), $resumed);
        $ledger->same('A resumed session reopens on the same question', 1, $resumed->json('data.current_position'), $resumed);
        $ledger->same(
            'A resumed session serves the same question it stopped on',
            (int) $questions[0]['id'],
            (int) $resumed->json('data.current_question_id'),
            $resumed,
        );

        $ledger->check(
            'A resumed question continues from the time already spent',
            (int) $resumed->json('data.questions.0.elapsed_seconds') >= $bankedQuestionTime,
            sprintf(
                'elapsed_seconds came back as %s, having banked %d.',
                var_export($resumed->json('data.questions.0.elapsed_seconds'), true),
                $bankedQuestionTime,
            ),
            $resumed,
        );

        // The questions and their order are untouched: resuming is not
        // regeneration.
        $orderAfter = DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->orderBy('position')
            ->pluck('fellowship_question_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $ledger->same(
            'Resuming does not regenerate the session',
            array_map(static fn (array $question): int => (int) $question['fellowship_question_id'], $questions),
            $orderAfter,
            $resumed,
        );

        // A second resume must be refused, and must not open a second period.
        $resumeAgain = $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/resume');

        $ledger->same('A running session cannot be resumed again (422)', 422, $resumeAgain->status, $resumeAgain);
        $ledger->same(
            'The repeated resume says why',
            'FELLOWSHIP_SESSION_ALREADY_IN_PROGRESS',
            $resumeAgain->json('code'),
            $resumeAgain,
        );

        $ledger->same(
            'Repeated pause and resume never leave two open periods',
            1,
            (int) DB::table('fellowship_session_periods')
                ->where('fellowship_quiz_session_id', $sessionId)
                ->whereNull('ended_at')
                ->count(),
        );

        // Several pause/resume cycles, then the same check again.
        for ($cycle = 0; $cycle < 3; $cycle++) {
            $this->advanceSeconds(10);
            $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/pause');
            $this->advanceSeconds(10);
            $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/resume');
        }

        $ledger->same(
            'Three pause/resume cycles still leave exactly one open period',
            1,
            (int) DB::table('fellowship_session_periods')
                ->where('fellowship_quiz_session_id', $sessionId)
                ->whereNull('ended_at')
                ->count(),
        );

        $ledger->check(
            'Each cycle recorded its own closed period',
            (int) DB::table('fellowship_session_periods')
                ->where('fellowship_quiz_session_id', $sessionId)
                ->whereNotNull('ended_at')
                ->count() >= 4,
            'Fewer closed periods than pause cycles were recorded.',
        );

        // Closed periods must carry a sane duration.
        $negative = (int) DB::table('fellowship_session_periods')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->whereNotNull('ended_at')
            ->where(function ($query): void {
                $query->where('duration_seconds', '<', 0)
                    ->orWhereNull('duration_seconds');
            })
            ->count();

        $ledger->same('No closed period has a missing or negative duration', 0, $negative);

        $ledger->same(
            'The session is left in progress after the last resume',
            FellowshipSessionStatus::InProgress,
            FellowshipQuizSession::query()->find($sessionId)?->status,
        );
    }

    /**
     * Walking away is not pausing. A session left open keeps counting, which is
     * the stated behaviour rather than an oversight.
     */
    private function closingTheBrowserDoesNotStopTheClock(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Timer · Walking away');

        $sessionId = $context->sessions['paused'] ?? null;

        if ($sessionId === null) {
            $ledger->skip('Walking away does not stop the clock', 'No open session.');

            return;
        }

        $before = (int) $context->api->get('/api/student/fellowship-sessions/'.$sessionId)->json('data.study_time_seconds');

        // No pause, no request at all — just time passing, as if the tab were
        // closed.
        $this->advanceSeconds(10 * 60);

        $after = (int) $context->api->get('/api/student/fellowship-sessions/'.$sessionId)->json('data.study_time_seconds');

        $ledger->check(
            'A session left open without pausing keeps accumulating time',
            $after >= $before + 600,
            sprintf('study time went from %d to %d over ten minutes.', $before, $after),
        );

        $ledger->same(
            'Walking away leaves the session in progress rather than abandoning it',
            FellowshipSessionStatus::InProgress,
            FellowshipQuizSession::query()->find($sessionId)?->status,
        );

        $ledger->same(
            'Walking away leaves its activity period open',
            1,
            (int) DB::table('fellowship_session_periods')
                ->where('fellowship_quiz_session_id', $sessionId)
                ->whereNull('ended_at')
                ->count(),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Move the clock forward.
     *
     * Always forward, never back: the server records a period's start from the
     * clock as it was, so stepping the clock backwards mid-scenario would leave
     * a period that began after the moment it is being measured at, and every
     * duration computed from it would shrink as time passed.
     */
    private function advanceSeconds(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    /**
     * Pause every session this scenario left running, so none is left holding an
     * activity period that starts in the future once the clock is restored.
     */
    private function closeTravelledSessions(FellowshipSmokeContext $context): void
    {
        foreach (['timed', 'untimed', 'paused'] as $key) {
            $sessionId = $context->sessions[$key] ?? null;

            if ($sessionId === null) {
                continue;
            }

            $session = FellowshipQuizSession::query()->find($sessionId);

            if ($session?->isInProgress() === true) {
                $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/pause');
            }
        }
    }

    /**
     * Generate a session through the API and return its id and questions.
     *
     * @return array{0: int, 1: list<array<string, mixed>>}|null
     */
    private function generate(FellowshipSmokeContext $context, string $label, int $questionCount, bool $timerEnabled): ?array
    {
        $ledger = $context->ledger;
        $id = $context->fellowship?->getKey();

        $response = $context->api->post('/api/student/fellowships/'.$id.'/sessions', [
            'title' => FellowshipSmokeFixtures::MARKER.$label,
            'question_count' => $questionCount,
            'timer_enabled' => $timerEnabled,
        ]);

        if (! $ledger->same($label.' session generated (201)', 201, $response->status, $response)) {
            return null;
        }

        /** @var list<array<string, mixed>> $questions */
        $questions = $response->json('data.questions', []);

        if (count($questions) < $questionCount) {
            $ledger->fail($label.' session generated', 'The session came back with '.count($questions).' questions.');

            return null;
        }

        return [(int) $response->json('data.id'), $questions];
    }

    /**
     * The correct option key, read from the session question's frozen snapshot.
     */
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
