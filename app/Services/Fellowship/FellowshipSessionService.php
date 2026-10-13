<?php

namespace App\Services\Fellowship;

use App\Enums\FellowshipSessionStatus;
use App\Exceptions\Fellowship\FellowshipAccessException;
use App\Exceptions\Fellowship\FellowshipSessionException;
use App\Models\Fellowship;
use App\Models\FellowshipQuizSession;
use App\Models\FellowshipSessionPeriod;
use App\Models\FellowshipSessionQuestion;
use App\Models\User;
use App\Support\Fellowship\FellowshipQuestionSnapshot;
use App\Support\Fellowship\FellowshipSettings;
use App\Support\Fellowship\SessionGenerationCriteria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The student's run through a Fellowship session: generating it, answering it in
 * order, and pausing and resuming it.
 *
 * Generation is the one moment the live questions are read. Each drawn question
 * is frozen into the session as a snapshot, and from then on the session is
 * presented, graded and attributed entirely from those snapshots — so editing,
 * re-filing or deleting a question afterwards cannot change what a session
 * showed or what it scored.
 *
 * Navigation is strictly sequential. The student answers the question they are
 * on, the answer is final, and only then does the next one become answerable;
 * there is no Skip and no Finish Early. The position is persisted, so a paused
 * session reopens on exactly the question it stopped on, with the same questions
 * in the same order.
 *
 * Timing is advisory throughout. `answered_within_time` is computed from the
 * server's own timestamps and the question's accumulated active time, recorded
 * in the same write as the answer, and never consulted by anything that scores.
 */
class FellowshipSessionService
{
    public function __construct(private FellowshipQuestionPoolService $pool) {}

    /**
     * Generate a session over the Fellowship's bank.
     *
     * The pool is counted before anything is written: if it cannot fill the
     * requested count the caller is told how many are actually available and no
     * session — not even a partial one — is created.
     */
    public function generate(
        User $student,
        Fellowship $fellowship,
        string $title,
        int $questionCount,
        SessionGenerationCriteria $criteria,
    ): FellowshipQuizSession {
        $this->assertFellowshipAvailable($fellowship);

        $available = $this->pool->count($fellowship, $student, $criteria);

        if ($available < $questionCount) {
            throw FellowshipSessionException::insufficientQuestions($available);
        }

        $questions = $this->pool->draw($fellowship, $student, $criteria, $questionCount);

        // The pool was counted a moment ago; a question soft-deleted in between
        // would leave the draw short, and a short session is exactly what must
        // not be created.
        if ($questions->count() < $questionCount) {
            throw FellowshipSessionException::insufficientQuestions($questions->count());
        }

        $timeLimit = $criteria->timerEnabled ? FellowshipSettings::questionTimeLimitSeconds() : null;

        return DB::transaction(function () use ($student, $fellowship, $title, $questionCount, $criteria, $questions, $timeLimit): FellowshipQuizSession {
            $now = now();

            /** @var FellowshipQuizSession $session */
            $session = $fellowship->quizSessions()->create([
                'user_id' => $student->getKey(),
                'title' => $title,
                'status' => FellowshipSessionStatus::InProgress,
                'question_count' => $questionCount,
                'current_position' => 1,
                'timer_enabled' => $criteria->timerEnabled,
                'question_time_limit_seconds' => $timeLimit,
                'generation_criteria' => $criteria->toArray(),
                'started_at' => $now,
            ]);

            foreach ($questions->values() as $index => $question) {
                $snapshot = FellowshipQuestionSnapshot::fromQuestion($question);

                $session->questions()->create([
                    'fellowship_question_id' => $question->getKey(),
                    'position' => $index + 1,
                    'question_snapshot' => $snapshot->toArray(),
                    'difficulty' => $question->difficulty?->value,
                    'performance_filter_value_id' => $snapshot->topicValueId(),
                    'topic_value' => $snapshot->topicValue(),
                    // The first question's clock starts with the session.
                    'activated_at' => $index === 0 ? $now : null,
                ]);
            }

            $this->openPeriod($session, $now);

            return $session->load('questions');
        });
    }

    /**
     * Record the student's answer, final from this point on.
     *
     * The chosen option is validated against the session question's own
     * snapshot and its correctness read from there — never from the request, and
     * never from the live option rows, which may have moved on. The answer, the
     * advisory timing verdict and the advance to the next question all land in
     * one transaction, so a session can never be left having scored an answer it
     * did not move past.
     */
    public function answer(
        User $student,
        FellowshipQuizSession $session,
        FellowshipSessionQuestion $sessionQuestion,
        string $optionKey,
    ): FellowshipSessionQuestion {
        $this->assertOwnedBy($student, $session);

        if ($sessionQuestion->fellowship_quiz_session_id !== $session->getKey()) {
            throw FellowshipSessionException::questionNotInSession();
        }

        $answeredId = DB::transaction(function () use ($session, $sessionQuestion, $optionKey): int {
            /** @var FellowshipQuizSession $locked */
            $locked = FellowshipQuizSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isCompleted()) {
                throw FellowshipSessionException::sessionAlreadyCompleted();
            }

            if (! $locked->isInProgress()) {
                throw FellowshipSessionException::sessionNotInProgress();
            }

            /** @var FellowshipSessionQuestion $question */
            $question = FellowshipSessionQuestion::query()
                ->whereKey($sessionQuestion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Re-read under the lock, so two submissions racing on the same
            // question cannot both find it unanswered.
            if ($question->isAnswered()) {
                throw FellowshipSessionException::questionAlreadyAnswered();
            }

            if ($question->position !== $locked->current_position) {
                throw FellowshipSessionException::questionOutOfOrder();
            }

            $snapshot = $question->snapshot();

            if (! $snapshot->hasOption($optionKey)) {
                throw FellowshipSessionException::optionNotInQuestion();
            }

            $now = now();

            $question->update([
                'selected_option_key' => $optionKey,
                // Read from the snapshot and from nothing else. Timing never
                // takes part in this.
                'is_correct' => $snapshot->isCorrect($optionKey),
                'answered_at' => $now,
                'answered_within_time' => $this->timingVerdict($locked, $question, $now),
                'active_seconds' => $question->elapsedSeconds($now),
                'activated_at' => null,
            ]);

            $this->advance($locked, $now);

            return $question->getKey();
        });

        /** @var FellowshipSessionQuestion $answered */
        $answered = FellowshipSessionQuestion::query()->findOrFail($answeredId);

        return $answered;
    }

    /**
     * Pause the session: stop its activity period and bank the current
     * question's elapsed time.
     *
     * This is the only thing that stops a question's clock. Closing the browser
     * or losing connectivity does not, by design.
     */
    public function pause(User $student, FellowshipQuizSession $session): FellowshipQuizSession
    {
        $this->assertOwnedBy($student, $session);

        DB::transaction(function () use ($session): void {
            /** @var FellowshipQuizSession $locked */
            $locked = FellowshipQuizSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isCompleted()) {
                throw FellowshipSessionException::sessionAlreadyCompleted();
            }

            if ($locked->isPaused()) {
                throw FellowshipSessionException::sessionAlreadyPaused();
            }

            $now = now();

            $this->bankCurrentQuestionTime($locked, $now);
            $this->closeOpenPeriod($locked, $now);

            $locked->update(['status' => FellowshipSessionStatus::Paused]);
        });

        return $session->refresh();
    }

    /**
     * Resume the session: open a new activity period and restart the current
     * question's clock from where it stopped.
     *
     * The questions and their order are untouched — resuming is not
     * regeneration.
     */
    public function resume(User $student, FellowshipQuizSession $session): FellowshipQuizSession
    {
        $this->assertOwnedBy($student, $session);

        DB::transaction(function () use ($session): void {
            /** @var FellowshipQuizSession $locked */
            $locked = FellowshipQuizSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isCompleted()) {
                throw FellowshipSessionException::sessionAlreadyCompleted();
            }

            if ($locked->isInProgress()) {
                throw FellowshipSessionException::sessionAlreadyInProgress();
            }

            $now = now();

            $locked->update(['status' => FellowshipSessionStatus::InProgress]);

            $current = $locked->currentQuestion();

            if ($current !== null && ! $current->isAnswered() && $current->activated_at === null) {
                $current->update(['activated_at' => $now]);
            }

            $this->openPeriod($locked, $now);
        });

        return $session->refresh();
    }

    public function assertOwnedBy(User $student, FellowshipQuizSession $session): void
    {
        if ($session->user_id !== $student->getKey()) {
            throw FellowshipAccessException::notFound();
        }
    }

    public function assertFellowshipAvailable(Fellowship $fellowship): void
    {
        if (! $fellowship->is_active) {
            throw FellowshipAccessException::fellowshipUnavailable();
        }
    }

    /**
     * Move to the next unanswered question, or complete the session.
     *
     * Completion is reached only by answering everything: there is no Finish
     * Early, so this is the single path to `completed`.
     */
    private function advance(FellowshipQuizSession $session, Carbon $now): void
    {
        /** @var FellowshipSessionQuestion|null $next */
        $next = $session->questions()
            ->unanswered()
            ->orderBy('position')
            ->first();

        if ($next === null) {
            $this->closeOpenPeriod($session, $now);

            $session->update([
                'status' => FellowshipSessionStatus::Completed,
                'completed_at' => $now,
            ]);

            return;
        }

        $session->update(['current_position' => $next->position]);

        // The next question's clock starts the moment it becomes the active one.
        if ($next->activated_at === null) {
            $next->update(['activated_at' => $now]);
        }
    }

    /**
     * Whether a timed question was answered inside its limit.
     *
     * Null when the session was generated with timing off — the field is then
     * simply not applicable. Computed from the server's own clock and the
     * question's accumulated active time, never from anything the client sends,
     * and informational only: a late answer is still graded exactly as an
     * on-time one.
     */
    private function timingVerdict(FellowshipQuizSession $session, FellowshipSessionQuestion $question, Carbon $now): ?bool
    {
        if (! $session->timer_enabled || $session->question_time_limit_seconds === null) {
            return null;
        }

        return $question->elapsedSeconds($now) <= (int) $session->question_time_limit_seconds;
    }

    /**
     * Bank the running stretch of the current question's clock, so a resume
     * continues from the remaining time rather than from the full limit.
     */
    private function bankCurrentQuestionTime(FellowshipQuizSession $session, Carbon $now): void
    {
        $current = $session->currentQuestion();

        if ($current === null || $current->activated_at === null) {
            return;
        }

        $current->update([
            'active_seconds' => $current->elapsedSeconds($now),
            'activated_at' => null,
        ]);
    }

    /**
     * Open an activity period, unless one is already open.
     *
     * The table admits only one open period per session, so a duplicate
     * resume is refused by the database as well as by the status check above.
     */
    private function openPeriod(FellowshipQuizSession $session, Carbon $startedAt): FellowshipSessionPeriod
    {
        $existing = $session->openPeriod();

        if ($existing !== null) {
            return $existing;
        }

        /** @var FellowshipSessionPeriod $period */
        $period = $session->periods()->create([
            'started_at' => $startedAt,
            'open_marker' => FellowshipSessionPeriod::OPEN,
        ]);

        return $period;
    }

    private function closeOpenPeriod(FellowshipQuizSession $session, Carbon $endedAt): void
    {
        $period = $session->openPeriod();

        if ($period === null) {
            return;
        }

        $period->update([
            'ended_at' => $endedAt,
            'duration_seconds' => max(0, (int) $period->started_at->diffInSeconds($endedAt, absolute: true)),
            'open_marker' => null,
        ]);
    }
}
