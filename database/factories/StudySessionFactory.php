<?php

namespace Database\Factories;

use App\Models\StudySession;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudySession>
 */
class StudySessionFactory extends Factory
{
    /**
     * A session starts open: ending one is a deliberate student action, so the
     * default state mirrors that.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject_id' => Subject::factory(),
            'started_at' => now(),
            'ended_at' => null,
            'duration_seconds' => null,
        ];
    }

    public function ended(int $durationSeconds = 600): static
    {
        return $this->state(function (array $attributes) use ($durationSeconds): array {
            $startedAt = $attributes['started_at'] ?? now();

            return [
                'ended_at' => $startedAt->copy()->addSeconds($durationSeconds),
                'duration_seconds' => $durationSeconds,
            ];
        });
    }
}
