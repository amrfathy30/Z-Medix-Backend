<?php

namespace App\Services\Subscription;

use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscription\SubscriptionException;
use App\Models\PlanFeatureLimit;
use App\Models\StudentSubscription;
use App\Models\SubscriptionPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single place that decides what a student's plan lets them use.
 *
 * Nothing here is cached on the student: the period that covers "now" is read
 * each time, so a payment recorded by the admin or a period running out takes
 * effect on the very next request.
 */
class FeatureAccessService
{
    public function subscriptionFor(User $student): ?StudentSubscription
    {
        return StudentSubscription::query()->where('user_id', $student->getKey())->first();
    }

    public function statusFor(User $student): SubscriptionStatus
    {
        return $this->subscriptionFor($student)?->status() ?? SubscriptionStatus::None;
    }

    /**
     * The period granting access right now, or null when the student has no
     * subscription, it was cancelled, or no period covers now.
     */
    public function currentPeriod(User $student): ?SubscriptionPeriod
    {
        $subscription = $this->subscriptionFor($student);

        if ($subscription === null || $subscription->isCancelled()) {
            return null;
        }

        return $subscription->currentPeriod();
    }

    /**
     * Every feature of the student's current plan, in the order the product
     * lists them. Empty when they have no active subscription.
     *
     * @return Collection<int, PlanFeatureLimit>
     */
    public function featuresFor(User $student): Collection
    {
        $plan = $this->currentPeriod($student)?->plan;

        return $plan === null ? new Collection : $plan->orderedFeatureLimits();
    }

    public function hasFeature(User $student, PlanFeature $feature): bool
    {
        return $this->featuresFor($student)->contains(
            fn (PlanFeatureLimit $limit): bool => $limit->feature === $feature,
        );
    }

    /**
     * The limit row for a feature the student's plan includes, for callers that
     * need the AI credit allowance. Null when the plan lacks the feature.
     */
    public function limitFor(User $student, PlanFeature $feature): ?PlanFeatureLimit
    {
        return $this->featuresFor($student)->first(
            fn (PlanFeatureLimit $limit): bool => $limit->feature === $feature,
        );
    }

    /**
     * Fails with "subscription required" when the student has nothing active,
     * and with "not in plan" when they do but their plan lacks the feature.
     *
     * @throws SubscriptionException
     */
    public function assertHasFeature(User $student, PlanFeature $feature): void
    {
        $period = $this->currentPeriod($student);

        if ($period === null) {
            throw SubscriptionException::subscriptionRequired();
        }

        if (! $period->plan->hasFeature($feature)) {
            throw SubscriptionException::featureNotInPlan($feature);
        }
    }
}
