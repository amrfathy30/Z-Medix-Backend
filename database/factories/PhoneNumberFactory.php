<?php

namespace Database\Factories;

use App\Enums\PhoneStatus;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<PhoneNumber>
 */
class PhoneNumberFactory extends Factory
{
    protected $model = PhoneNumber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => 'user',
            'owner_id' => User::factory(),
            'country_iso2' => 'EG',
            'country_code' => '+20',
            'national_number' => '1012345678',
            'e164_number' => '+201012345678',
            'is_primary' => false,
            'status' => PhoneStatus::Pending,
            'verified_at' => null,
            'last_used_at' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state([
            'status' => PhoneStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }

    public function forOwner(Model $owner): static
    {
        return $this->state([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }
}
