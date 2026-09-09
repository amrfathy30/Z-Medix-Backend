<?php

namespace Database\Factories;

use App\Enums\SettingValueType;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'value' => fake()->sentence(4),
            'value_type' => SettingValueType::String,
            'group' => fake()->randomElement(['general', 'home', 'footer', 'social']),
            'is_public' => true,
            'description' => fake()->sentence(6),
        ];
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => false,
        ]);
    }

    public function inGroup(string $group): static
    {
        return $this->state(fn (array $attributes) => [
            'group' => $group,
        ]);
    }
}
