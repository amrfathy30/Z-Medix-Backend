<?php

namespace Database\Factories;

use App\Models\ChapterPage;
use App\Models\StudentHighlight;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentHighlight>
 */
class StudentHighlightFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'chapter_page_id' => ChapterPage::factory(),
            'selected_text' => fake()->sentence(),
        ];
    }

    public function selectedText(string $selectedText): static
    {
        return $this->state(fn (array $attributes) => [
            'selected_text' => $selectedText,
        ]);
    }
}
