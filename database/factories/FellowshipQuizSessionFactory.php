<?php

namespace Database\Factories;

use App\Enums\FellowshipSessionStatus;
use App\Models\Fellowship;
use App\Models\FellowshipQuizSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FellowshipQuizSession>
 */
class FellowshipQuizSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'fellowship_id' => Fellowship::factory(),
            'title' => fake()->words(3, true),
            'status' => FellowshipSessionStatus::InProgress,
            'question_count' => 2,
            'current_position' => 1,
            'timer_enabled' => false,
            'question_time_limit_seconds' => null,
            'started_at' => now(),
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FellowshipSessionStatus::Paused,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FellowshipSessionStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function timed(int $limitSeconds = 60): static
    {
        return $this->state(fn (array $attributes): array => [
            'timer_enabled' => true,
            'question_time_limit_seconds' => $limitSeconds,
        ]);
    }
}
