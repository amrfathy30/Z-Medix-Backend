<?php

namespace Database\Factories;

use App\Models\Fellowship;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fellowship>
 */
class FellowshipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Give the Fellowship its Performance Topic filter, which is what the first
     * confirmed import would have designated.
     */
    public function withPerformanceFilter(string $name = 'Performance Topic'): static
    {
        return $this->afterCreating(function (Fellowship $fellowship) use ($name): void {
            $filter = $fellowship->filters()->create([
                'name' => $name,
                'is_performance' => true,
            ]);

            $fellowship->update(['performance_filter_id' => $filter->getKey()]);
        });
    }
}
