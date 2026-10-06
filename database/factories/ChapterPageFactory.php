<?php

namespace Database\Factories;

use App\Models\Chapter;
use App\Models\ChapterPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChapterPage>
 */
class ChapterPageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chapter_id' => Chapter::factory(),
            'content' => '<p>'.fake()->paragraph().'</p>',
        ];
    }

    public function order(int $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order' => $order,
        ]);
    }

    public function content(string $content): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $content,
        ]);
    }
}
