<?php

namespace App\Http\Resources\Api\Student;

use App\Models\SubscriptionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A student's own plan request. Admin-only fields (who handled it) are left out.
 *
 * @mixin SubscriptionRequest
 */
class SubscriptionRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'billing_cycle' => $this->billing_cycle->value,
            'plan' => [
                'id' => $this->plan->id,
                'slug' => $this->plan->slug,
                'name' => $this->plan->name,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
