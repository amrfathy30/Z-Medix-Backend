<?php

namespace Database\Factories;

use App\Models\FellowshipFilter;
use App\Models\FellowshipFilterValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FellowshipFilterValue>
 */
class FellowshipFilterValueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fellowship_filter_id' => FellowshipFilter::factory(),
            'value' => fake()->unique()->words(2, true),
        ];
    }
}
