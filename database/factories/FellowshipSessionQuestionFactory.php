<?php

namespace Database\Factories;

use App\Models\FellowshipQuestion;
use App\Models\FellowshipQuizSession;
use App\Models\FellowshipSessionQuestion;
use App\Support\Fellowship\FellowshipQuestionSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FellowshipSessionQuestion>
 */
class FellowshipSessionQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fellowship_quiz_session_id' => FellowshipQuizSession::factory(),
            'fellowship_question_id' => null,
            'position' => 1,
            'question_snapshot' => FellowshipQuestionSnapshot::fromArray([
                'question_text' => fake()->sentence(8).'?',
                'difficulty' => 'easy',
                'options' => [
                    ['key' => '1', 'option_text' => 'Option A', 'is_correct' => true, 'order' => 1],
                    ['key' => '2', 'option_text' => 'Option B', 'is_correct' => false, 'order' => 2],
                ],
            ])->toArray(),
            'difficulty' => 'easy',
            'topic_value' => null,
        ];
    }

    /**
     * Capture a real question, the way session generation would.
     */
    public function forQuestion(FellowshipQuestion $question, int $position = 1): static
    {
        $snapshot = FellowshipQuestionSnapshot::fromQuestion($question);

        return $this->state(fn (array $attributes): array => [
            'fellowship_question_id' => $question->getKey(),
            'position' => $position,
            'question_snapshot' => $snapshot->toArray(),
            'difficulty' => $question->difficulty?->value,
            'performance_filter_value_id' => $snapshot->topicValueId(),
            'topic_value' => $snapshot->topicValue(),
        ]);
    }
}
