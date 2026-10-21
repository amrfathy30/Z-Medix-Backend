<?php

namespace App\Http\Controllers\Api\Student;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\PlanFeatureResource;
use App\Http\Resources\Api\Student\SubscriptionRequestResource;
use App\Services\Subscription\FeatureAccessService;
use App\Support\Api\ApiResponse;
use App\Support\Subscription\SubscriptionContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the student stands: their plan, how long it lasts, and the features it
 * gives them. Open to every authenticated student.
 *
 * `features` is only ever the current plan's own features, and is empty without
 * an active subscription, so an app that renders from this list can never show a
 * feature the student cannot use.
 */
class SubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(private FeatureAccessService $access) {}

    public function show(Request $request): JsonResponse
    {
        $student = $request->user();

        $subscription = $this->access->subscriptionFor($student);
        $status = $subscription?->status() ?? SubscriptionStatus::None;
        $period = $status === SubscriptionStatus::Active ? $subscription->currentPeriod() : null;
        $price = $period === null ? null : $period->plan->priceFor($period->billing_cycle);

        $pending = $student->subscriptionRequests()
            ->pending()
            ->with('plan')
            ->first();

        return $this->successResponse([
            'status' => $status->value,
            'plan' => $period === null ? null : [
                'id' => $period->plan->id,
                'slug' => $period->plan->slug,
                'name' => $period->plan->name,
                // A plan with no description in the requested language
                // translates to an empty string; the app wants nothing at all.
                'description' => blank($period->plan->description) ? null : $period->plan->description,
                'price' => $price === null ? null : (float) $price,
                'currency' => $period->plan->currency,
            ],
            'billing_cycle' => $period?->billing_cycle->value,
            'starts_at' => $period?->starts_at->toIso8601String(),
            'ends_at' => $period?->ends_at->toIso8601String(),
            'days_remaining' => $period === null ? null : (int) ceil(max(0, now()->diffInSeconds($period->ends_at, false)) / 86400),
            'features' => $period === null
                ? []
                : PlanFeatureResource::collection($period->plan->orderedFeatureLimits()),
            'pending_request' => $pending === null ? null : new SubscriptionRequestResource($pending),
            'contact' => SubscriptionContact::toArray(),
        ]);
    }
}
