<?php

namespace Tests\Concerns;

use App\Enums\AccountStatus;
use App\Models\Plan;
use App\Models\StudentSubscription;
use App\Models\User;

/**
 * Student API routes are gated by the plan, so a test student needs a
 * subscription to reach them. These helpers give one, on the shared Ultra plan
 * (every feature) unless a test passes the plan it wants to exercise. The period
 * covers any frozen clock, because many tests pin `Carbon::setTestNow()` to a
 * fixed date; the tests of the plan gate itself build realistic periods instead.
 */
trait SubscribesStudents
{
    protected function subscribe(User $student, ?Plan $plan = null): StudentSubscription
    {
        return StudentSubscription::factory()
            ->for($student, 'user')
            ->perpetual($plan)
            ->create();
    }

    /**
     * An active student with an active subscription.
     */
    protected function subscribedStudent(?Plan $plan = null): User
    {
        $student = User::factory()->create(['status' => AccountStatus::Active]);

        $this->subscribe($student, $plan);

        return $student;
    }
}
