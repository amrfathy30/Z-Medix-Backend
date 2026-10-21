<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan as the pricing screen shows it: both billing cycles' prices, and the
 * features it includes. `is_current` marks the plan the student is on right now.
 *
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    public function __construct($resource, private readonly ?int $currentPlanId = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'currency' => $this->currency,
            'prices' => [
                'monthly' => $this->monthly_price === null ? null : (float) $this->monthly_price,
                'yearly' => $this->yearly_price === null ? null : (float) $this->yearly_price,
            ],
            'features' => PlanFeatureResource::collection($this->orderedFeatureLimits()),
            'is_current' => $this->currentPlanId !== null && $this->currentPlanId === $this->id,
        ];
    }
}
