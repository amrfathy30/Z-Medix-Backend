<?php

namespace App\Services\Learning;

use App\Exceptions\Learning\StudyAccessException;
use App\Exceptions\Learning\StudyFlowException;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * One subject timer per student, independent of reading progress.
 * Only an explicit end or a subject switch stops a normal session.
 */
class StudySessionService
{
    public function __construct(private StudyAccessService $access) {}

    public function start(User $student, Subject $subject): StudySession
    {
        $this->access->assertCanAccessSubject($student, $subject);

        return DB::transaction(function () use ($student, $subject): StudySession {
            User::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

            $session = $this->resolveOpenSessions($student);

            if ($session?->subject_id === $subject->getKey()) {
                return $session;
            }

            $transitionAt = now();

            if ($session !== null) {
                $this->close($session, $transitionAt);
            }

            return $student->studySessions()->create([
                'subject_id' => $subject->getKey(),
                'started_at' => $transitionAt,
            ]);
        }, attempts: 5);
    }

    public function end(User $student, StudySession $session): StudySession
    {
        if ($session->user_id !== $student->getKey()) {
            throw StudyAccessException::notFound();
        }

        return DB::transaction(function () use ($student, $session): StudySession {
            User::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

            $session = $student->studySessions()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->hasEnded()) {
                throw StudyFlowException::studySessionAlreadyEnded();
            }

            $openSession = $this->resolveOpenSessions($student);

            if ($openSession?->is($session)) {
                $this->close($session, now());
            }

            return $session->refresh();
        }, attempts: 5);
    }

    /**
     * Keep the newest legacy timer (ID breaks timestamp ties). The elapsed
     * time of superseded open timers cannot be attributed reliably. Preserve
     * their rows with zero-length intervals instead of inventing end times
     * or counting their overlapping time. Completed history is never changed.
     */
    private function resolveOpenSessions(User $student): ?StudySession
    {
        $sessions = $student->studySessions()->open()
            ->orderByDesc('started_at')->orderByDesc('id')
            ->lockForUpdate()->get();

        foreach ($sessions->skip(1) as $duplicate) {
            $this->close($duplicate, $duplicate->started_at);
        }

        return $sessions->first();
    }

    private function close(StudySession $session, CarbonInterface $endedAt): void
    {
        $session->update([
            'ended_at' => $endedAt,
            'duration_seconds' => max(0, (int) $session->started_at->diffInSeconds($endedAt, absolute: false)),
        ]);
    }
}
