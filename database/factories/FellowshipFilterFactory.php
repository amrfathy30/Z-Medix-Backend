<?php

namespace Database\Factories;

use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FellowshipFilter>
 */
class FellowshipFilterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fellowship_id' => Fellowship::factory(),
            'name' => fake()->unique()->words(2, true),
            'is_performance' => false,
        ];
    }

    public function performance(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_performance' => true,
        ]);
    }

    /**
     * Attach the given values to the filter.
     *
     * @param  list<string>  $values
     */
    public function withValues(array $values): static
    {
        return $this->afterCreating(function (FellowshipFilter $filter) use ($values): void {
            foreach ($values as $value) {
                $filter->values()->create(['value' => $value]);
            }
        });
    }
}
