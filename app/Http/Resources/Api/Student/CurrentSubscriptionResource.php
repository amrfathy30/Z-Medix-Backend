<?php

namespace App\Http\Resources\Api\Student;

use App\Enums\SubscriptionStatus;
use App\Services\Subscription\FeatureAccessService;
use App\Support\Subscription\SubscriptionContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrentSubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $access = app(FeatureAccessService::class);
        $student = $this->resource;

        $subscription = $access->subscriptionFor($student);
        $status = $subscription?->status() ?? SubscriptionStatus::None;
        $period = $status === SubscriptionStatus::Active ? $subscription->currentPeriod() : null;
        $price = $period === null ? null : $period->plan->priceFor($period->billing_cycle);

        $pending = $student->subscriptionRequests()
            ->pending()
            ->with('plan')
            ->first();

        return [
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
        ];
    }
}
