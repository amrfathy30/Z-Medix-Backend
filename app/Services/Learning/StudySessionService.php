<?php

namespace App\Services\Learning;

use App\Exceptions\Learning\StudyAccessException;
use App\Exceptions\Learning\StudyFlowException;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\User;

/**
 * Opens and closes the clock on a student's study time.
 *
 * A session is only a measurement: it carries no progress, so starting one is
 * never required to read content and ending one never loses the student's
 * place. Several may be open at once, nothing closes one on the student's
 * behalf, and studying again later is a new session rather than a reopened one.
 */
class StudySessionService
{
    public function __construct(private StudyAccessService $access) {}

    public function start(User $student, Subject $subject): StudySession
    {
        $this->access->assertCanAccessSubject($student, $subject);

        /** @var StudySession $session */
        $session = $student->studySessions()->create([
            'subject_id' => $subject->getKey(),
            'started_at' => now(),
        ]);

        return $session;
    }

    /**
     * Close the session and store how long it ran, so total study time never
     * has to be re-derived from timestamps.
     */
    public function end(User $student, StudySession $session): StudySession
    {
        if ($session->user_id !== $student->getKey()) {
            throw StudyAccessException::notFound();
        }

        if ($session->hasEnded()) {
            throw StudyFlowException::studySessionAlreadyEnded();
        }

        $endedAt = now();

        $session->update([
            'ended_at' => $endedAt,
            'duration_seconds' => max(0, (int) $endedAt->diffInSeconds($session->started_at, absolute: true)),
        ]);

        return $session->refresh();
    }
}
