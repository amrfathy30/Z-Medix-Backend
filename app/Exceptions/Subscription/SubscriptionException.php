<?php

namespace App\Exceptions\Subscription;

use App\Enums\PlanFeature;
use App\Exceptions\Learning\StudyException;
use Illuminate\Http\Response;

/**
 * Business failures of the plans and subscriptions flow. Extends the study
 * exception base so the student API renders them in the same envelope, with a
 * stable machine-readable `code` the apps can branch on.
 */
class SubscriptionException extends StudyException
{
    /** The student has no subscription that is active right now. */
    public static function subscriptionRequired(): self
    {
        return new self(
            'An active subscription is required to access this.',
            'SUBSCRIPTION_REQUIRED',
            Response::HTTP_FORBIDDEN,
        );
    }

    /** The student's plan does not include the feature. */
    public static function featureNotInPlan(PlanFeature $feature): self
    {
        return new self(
            'Your current plan does not include this feature.',
            'FEATURE_NOT_IN_PLAN',
            Response::HTTP_FORBIDDEN,
        );
    }

    public static function planUnavailable(): self
    {
        return new self(
            'This plan is not available.',
            'PLAN_UNAVAILABLE',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function cycleNotOffered(): self
    {
        return new self(
            'This plan is not offered for the selected billing cycle.',
            'BILLING_CYCLE_NOT_OFFERED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function requestNotPending(): self
    {
        return new self(
            'This request has already been handled.',
            'REQUEST_NOT_PENDING',
            Response::HTTP_CONFLICT,
        );
    }

    public static function requestNotOwned(): self
    {
        return new self(
            'This request belongs to a different student.',
            'REQUEST_STUDENT_MISMATCH',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
