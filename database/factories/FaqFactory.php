<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Faq;
use App\Models\FaqCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 */
class FaqFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'faq_category_id' => FaqCategory::factory(),
            'question' => ['en' => fake()->sentence(8).'?', 'ar' => fake()->sentence(8).'؟'],
            'answer' => ['en' => fake()->paragraph(3), 'ar' => fake()->paragraph(3)],
            'status' => ContentStatus::Published,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Archived,
        ]);
    }

    public function withoutCategory(): static
    {
        return $this->state(fn (array $attributes) => [
            'faq_category_id' => null,
        ]);
    }
}
