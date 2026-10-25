<?php

namespace Database\Factories;

use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromoCode> */
class PromoCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('MEDIX-????-####')),
            'discount_percentage' => 20,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_usage_limit' => 100,
            'is_active' => true,
        ];
    }
}
