<?php

namespace Database\Factories;

use App\Enums\QuizDifficulty;
use App\Models\Fellowship;
use App\Models\FellowshipQuestion;
use App\Models\FellowshipQuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FellowshipQuestion>
 */
class FellowshipQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fellowship_id' => Fellowship::factory(),
            'question_text' => fake()->unique()->sentence(10).'?',
            'explanation' => fake()->sentence(12),
            'difficulty' => fake()->randomElement(QuizDifficulty::cases()),
        ];
    }

    public function difficulty(QuizDifficulty $difficulty): static
    {
        return $this->state(fn (array $attributes): array => [
            'difficulty' => $difficulty,
        ]);
    }

    /**
     * Attach a valid option set: at least two options, exactly one correct, the
     * first being the correct one so tests can address it predictably.
     */
    public function withOptions(int $count = 4): static
    {
        return $this->afterCreating(function (FellowshipQuestion $question) use ($count): void {
            foreach (range(1, max(2, $count)) as $index) {
                FellowshipQuestionOption::factory()->create([
                    'fellowship_question_id' => $question->getKey(),
                    'is_correct' => $index === 1,
                    'order' => $index,
                ]);
            }
        });
    }
}
