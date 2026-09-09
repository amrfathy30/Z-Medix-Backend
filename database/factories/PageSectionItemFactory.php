<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\PageSection;
use App\Models\PageSectionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PageSectionItem> */
class PageSectionItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'page_section_id' => PageSection::factory(),
            'title' => ['en' => fake()->unique()->words(3, true), 'ar' => fake()->words(3, true)],
            'description' => ['en' => fake()->sentence(10), 'ar' => fake()->sentence(10)],
            'icon' => null,
            'link' => null,
            'sort_order' => 0,
            'status' => ContentStatus::Published,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => ContentStatus::Draft]);
    }

    public function forSection(PageSection $section): static
    {
        return $this->state(fn (array $attributes): array => ['page_section_id' => $section->id]);
    }
}
