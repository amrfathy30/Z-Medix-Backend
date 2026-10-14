<?php

namespace Database\Factories;

use App\Models\FellowshipQuestion;
use App\Models\FellowshipQuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FellowshipQuestionOption>
 */
class FellowshipQuestionOptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fellowship_question_id' => FellowshipQuestion::factory(),
            'option_text' => fake()->unique()->sentence(4),
            'is_correct' => false,
            'order' => 0,
        ];
    }

    public function correct(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_correct' => true,
        ]);
    }
}
