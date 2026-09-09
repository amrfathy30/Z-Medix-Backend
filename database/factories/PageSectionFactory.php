<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Page;
use App\Models\PageSection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PageSection>
 */
class PageSectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $titleEn = fake()->unique()->sentence(3);

        return [
            'page_id' => Page::factory(),
            'section_key' => Str::slug($titleEn),
            'label' => $titleEn,
            'data' => [
                'title' => ['en' => $titleEn, 'ar' => fake()->sentence(3)],
                'subtitle' => ['en' => fake()->sentence(6), 'ar' => fake()->sentence(6)],
                'body' => ['en' => fake()->paragraph(), 'ar' => fake()->paragraph()],
            ],
            'sort_order' => 0,
            'status' => ContentStatus::Published,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ContentStatus::Draft,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ContentStatus::Archived,
        ]);
    }

    public function forPage(Page $page): static
    {
        return $this->state(fn (array $attributes): array => [
            'page_id' => $page->id,
        ]);
    }
}
