<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\FaqCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FaqCategory>
 */
class FaqCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nameEn = fake()->unique()->words(2, true);

        return [
            'name' => ['en' => ucwords($nameEn), 'ar' => fake()->words(2, true)],
            'slug' => Str::slug($nameEn),
            'description' => ['en' => fake()->sentence(), 'ar' => fake()->sentence()],
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
}
