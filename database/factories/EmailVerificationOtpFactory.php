<?php

namespace Database\Factories;

use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<EmailVerificationOtp>
 */
class EmailVerificationOtpFactory extends Factory
{
    protected $model = EmailVerificationOtp::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'purpose' => OtpPurpose::EmailVerification,
            'code_hash' => Hash::make('123456'),
            'status' => OtpStatus::Pending,
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    public function withCode(string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'code_hash' => Hash::make($code),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OtpStatus::Verified,
            'verified_at' => now(),
        ]);
    }
}
