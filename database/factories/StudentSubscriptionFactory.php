<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\StudentSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentSubscription>
 */
class StudentSubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }

    public function cancelled(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    /**
     * A subscription with a monthly period covering now on the given plan
     * (the shared Ultra plan by default).
     */
    public function active(?Plan $plan = null): static
    {
        return $this->afterCreating(function (StudentSubscription $subscription) use ($plan): void {
            SubscriptionPeriodFactory::new()
                ->current()
                ->create([
                    'student_subscription_id' => $subscription->getKey(),
                    'plan_id' => ($plan ?? PlanFactory::ultraPlan())->getKey(),
                ]);
        });
    }

    /**
     * Like {@see active()}, but the period covers any frozen clock a test sets.
     */
    public function perpetual(?Plan $plan = null): static
    {
        return $this->afterCreating(function (StudentSubscription $subscription) use ($plan): void {
            SubscriptionPeriodFactory::new()
                ->alwaysCovering()
                ->create([
                    'student_subscription_id' => $subscription->getKey(),
                    'plan_id' => ($plan ?? PlanFactory::ultraPlan())->getKey(),
                ]);
        });
    }

    /**
     * A subscription whose only period ended in the past.
     */
    public function expired(?Plan $plan = null): static
    {
        return $this->afterCreating(function (StudentSubscription $subscription) use ($plan): void {
            SubscriptionPeriodFactory::new()
                ->ended()
                ->create([
                    'student_subscription_id' => $subscription->getKey(),
                    'plan_id' => ($plan ?? PlanFactory::ultraPlan())->getKey(),
                ]);
        });
    }
}
