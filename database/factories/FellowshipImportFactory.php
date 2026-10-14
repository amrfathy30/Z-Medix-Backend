<?php

namespace Database\Factories;

use App\Enums\FellowshipImportStatus;
use App\Models\Fellowship;
use App\Models\FellowshipImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FellowshipImport>
 */
class FellowshipImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fellowship_id' => Fellowship::factory(),
            'status' => FellowshipImportStatus::Staged,
            'original_filename' => fake()->slug(2).'.xlsx',
            'headers' => [],
            'header_map' => [],
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FellowshipImportStatus::Completed,
            'confirmed_at' => now(),
        ]);
    }
}
