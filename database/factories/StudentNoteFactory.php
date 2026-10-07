<?php

namespace Database\Factories;

use App\Models\ChapterPage;
use App\Models\StudentNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentNote>
 */
class StudentNoteFactory extends Factory
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
            'content' => fake()->paragraph(),
        ];
    }

    public function selectedText(string $selectedText): static
    {
        return $this->state(fn (array $attributes) => [
            'selected_text' => $selectedText,
        ]);
    }

    public function content(string $content): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $content,
        ]);
    }
}
