<?php

namespace Database\Factories;

use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Models\PhoneNumber;
use App\Models\PhoneVerificationOtp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhoneVerificationOtp>
 */
class PhoneVerificationOtpFactory extends Factory
{
    protected $model = PhoneVerificationOtp::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone_number_id' => PhoneNumber::factory(),
            'purpose' => OtpPurpose::PhoneVerification,
            'code_hash' => 'twilio_verify:VE'.fake()->regexify('[A-Za-z0-9]{32}'),
            'status' => OtpStatus::Pending,
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
            'verified_at' => null,
            'ip_address' => null,
            'user_agent' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state([
            'status' => OtpStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => OtpStatus::Expired,
            'expires_at' => now()->subMinutes(5),
        ]);
    }
}
