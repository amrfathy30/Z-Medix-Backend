<?php

namespace Database\Factories;

use App\Enums\FellowshipImportRowStatus;
use App\Models\FellowshipImport;
use App\Models\FellowshipImportRow;
use App\Support\Fellowship\ImportRowPayload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FellowshipImportRow>
 */
class FellowshipImportRowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fellowship_import_id' => FellowshipImport::factory(),
            'row_number' => fake()->unique()->numberBetween(2, 5000),
            'status' => FellowshipImportRowStatus::Valid,
            'payload' => ImportRowPayload::make(
                questionText: fake()->sentence(8).'?',
                explanation: fake()->sentence(10),
                difficulty: 'easy',
                correctAnswer: 'Option A',
                performanceTopic: 'Cardiology',
                options: ['Option A', 'Option B'],
                filters: [],
            )->toArray(),
        ];
    }
}
