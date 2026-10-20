<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionRequestStatus;
use App\Models\Plan;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionRequest>
 */
class SubscriptionRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'billing_cycle' => BillingCycle::Monthly,
            'status' => SubscriptionRequestStatus::Pending,
        ];
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => ['billing_cycle' => BillingCycle::Yearly]);
    }

    public function rejected(?string $note = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionRequestStatus::Rejected,
            'admin_note' => $note,
            'handled_at' => now(),
        ]);
    }
}
