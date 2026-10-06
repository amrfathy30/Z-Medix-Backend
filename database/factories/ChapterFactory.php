<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Enums\QuizDifficulty;
use App\Models\Chapter;
use App\Models\Subject;
use App\Services\Learning\ChapterService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chapter>
 */
class ChapterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'title' => fake()->unique()->sentence(3),
            'status' => ContentStatus::Published,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Draft,
        ]);
    }

    /**
     * Every chapter created through the admin owns a quiz; this state mirrors
     * that for tests that need a realistic chapter.
     */
    public function withQuiz(?string $title = null, QuizDifficulty $difficulty = QuizDifficulty::Medium): static
    {
        return $this->afterCreating(function (Chapter $chapter) use ($title, $difficulty): void {
            $chapter->quiz()->create([
                'title' => $title ?? ChapterService::defaultQuizTitle((string) $chapter->title),
                'difficulty' => $difficulty,
            ]);

            $chapter->unsetRelation('quiz');
        });
    }

    public function order(int $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order' => $order,
        ]);
    }
}
