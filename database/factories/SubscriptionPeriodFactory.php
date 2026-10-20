<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\Plan;
use App\Models\StudentSubscription;
use App\Models\SubscriptionPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;

/**
 * @extends Factory<SubscriptionPeriod>
 */
class SubscriptionPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_subscription_id' => StudentSubscription::factory(),
            'plan_id' => Plan::factory(),
            'billing_cycle' => BillingCycle::Monthly,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addMonthNoOverflow(),
            'amount' => 9.99,
            'currency' => 'USD',
            'paid_at' => now()->subDay(),
            'notes' => null,
            'recorded_by' => null,
        ];
    }

    /** Started yesterday and runs for the rest of the month. */
    public function current(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addMonthNoOverflow(),
        ]);
    }

    /**
     * Covers every moment a test could freeze the clock at. For tests that need
     * "a student with access" and travel through time, where a realistic
     * month-long period would lapse or not have started yet.
     */
    public function alwaysCovering(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => '2000-01-01 00:00:00',
            'ends_at' => '2099-12-31 00:00:00',
        ]);
    }

    /** Ended yesterday. */
    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subDay()->subMonthNoOverflow(),
            'ends_at' => now()->subDay(),
        ]);
    }

    /** Starts in the future (a renewal paid ahead of time). */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(5)->addMonthNoOverflow(),
        ]);
    }

    public function yearly(): static
    {
        return $this->state(function (array $attributes) {
            $start = $attributes['starts_at'];

            return [
                'billing_cycle' => BillingCycle::Yearly,
                'ends_at' => BillingCycle::Yearly->endsAt($start),
            ];
        });
    }

    /** A period with its screenshot already in the payment_proof collection. */
    public function withProof(string $fileName = 'proof.jpg'): static
    {
        return $this->afterCreating(function (SubscriptionPeriod $period) use ($fileName): void {
            $period->addMedia(self::fakeProof($fileName))
                ->toMediaCollection(SubscriptionPeriod::PROOF_COLLECTION);

            $period->unsetRelation('media');
        });
    }

    /** An uploadable screenshot that passes the collection's MIME check. */
    public static function fakeProof(string $fileName = 'proof.jpg'): UploadedFile|File
    {
        return UploadedFile::fake()->image($fileName, 400, 600);
    }
}
