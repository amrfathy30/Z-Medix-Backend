<?php

namespace Database\Factories;

use App\Enums\QuizDifficulty;
use App\Models\Chapter;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chapter_id' => Chapter::factory(),
            'title' => fake()->unique()->sentence(3),
            'difficulty' => QuizDifficulty::Medium,
        ];
    }

    public function difficulty(QuizDifficulty $difficulty): static
    {
        return $this->state(fn (array $attributes) => [
            'difficulty' => $difficulty,
        ]);
    }
}
