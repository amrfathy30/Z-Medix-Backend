<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Blog;
use App\Models\BlogCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Blog>
 */
class BlogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $titleEn = fake()->unique()->sentence(4);

        return [
            'blog_category_id' => BlogCategory::factory(),
            'title' => ['en' => $titleEn, 'ar' => fake()->sentence(4)],
            'slug' => Str::slug($titleEn),
            'excerpt' => ['en' => fake()->sentence(15), 'ar' => fake()->sentence(15)],
            'content' => ['en' => fake()->paragraphs(5, true), 'ar' => fake()->paragraphs(5, true)],
            'meta_title' => ['en' => $titleEn, 'ar' => fake()->sentence(4)],
            'meta_description' => ['en' => fake()->sentence(12), 'ar' => fake()->sentence(12)],
            'status' => ContentStatus::Published,
            'published_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Draft,
            'published_at' => null,
        ]);
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
            'blog_category_id' => null,
        ]);
    }
}
