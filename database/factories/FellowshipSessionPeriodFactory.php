<?php

namespace Database\Factories;

use App\Models\FellowshipQuizSession;
use App\Models\FellowshipSessionPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FellowshipSessionPeriod>
 */
class FellowshipSessionPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fellowship_quiz_session_id' => FellowshipQuizSession::factory(),
            'started_at' => now(),
            'ended_at' => null,
            'duration_seconds' => null,
            'open_marker' => FellowshipSessionPeriod::OPEN,
        ];
    }

    public function closed(int $durationSeconds = 600): static
    {
        return $this->state(function (array $attributes) use ($durationSeconds): array {
            $startedAt = $attributes['started_at'] ?? now();

            return [
                'ended_at' => $startedAt->copy()->addSeconds($durationSeconds),
                'duration_seconds' => $durationSeconds,
                'open_marker' => null,
            ];
        });
    }
}
