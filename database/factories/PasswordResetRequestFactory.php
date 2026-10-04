<?php

namespace Database\Factories;

use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PasswordResetRequest>
 */
class PasswordResetRequestFactory extends Factory
{
    protected $model = PasswordResetRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', fake()->sha256()),
            'expires_at' => now()->addMinutes(15),
            'used_at' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    public function withToken(string $plainToken): static
    {
        return $this->state(fn (array $attributes): array => [
            'token_hash' => hash('sha256', $plainToken),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes): array => [
            'used_at' => now(),
        ]);
    }
}
