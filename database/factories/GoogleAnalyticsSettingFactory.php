<?php

namespace Database\Factories;

use App\Models\GoogleAnalyticsSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoogleAnalyticsSetting> */
class GoogleAnalyticsSettingFactory extends Factory
{
    protected $model = GoogleAnalyticsSetting::class;

    public function definition(): array
    {
        return [
            'enabled' => false,
            'measurement_id' => null,
            'api_secret' => null,
            'property_id' => null,
            'service_account_json' => null,
        ];
    }

    public function configured(): static
    {
        return $this->state(fn (): array => [
            'enabled' => true,
            'measurement_id' => 'G-'.strtoupper(fake()->bothify('##########')),
            'api_secret' => fake()->regexify('[A-Za-z0-9_-]{22}'),
        ]);
    }

    public function withReportingCredentials(): static
    {
        return $this->state(fn (): array => [
            'property_id' => (string) fake()->numberBetween(100000000, 999999999),
            'service_account_json' => json_encode([
                'type' => 'service_account',
                'project_id' => fake()->slug(2),
                'private_key_id' => fake()->regexify('[a-f0-9]{40}'),
                'private_key' => 'not-a-real-key',
                'client_email' => fake()->unique()->safeEmail(),
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
