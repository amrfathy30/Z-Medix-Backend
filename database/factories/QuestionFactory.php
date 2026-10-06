<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'question' => fake()->sentence(8).'?',
        ];
    }

    /**
     * Attach a valid option set: at least two options, exactly one correct.
     */
    public function withOptions(int $count = 4): static
    {
        return $this->afterCreating(function (Question $question) use ($count): void {
            foreach (range(1, max(2, $count)) as $index) {
                QuestionOption::factory()->create([
                    'question_id' => $question->id,
                    'is_correct' => $index === 1,
                    'order' => $index,
                ]);
            }
        });
    }
}
