<?php

namespace App\Http\Resources\Api\Student;

use App\Models\PlanFeatureLimit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One feature a plan includes. `limit` and `unlimited` only mean something for
 * a limitable feature (AI credits); for the plain on/off features they stay
 * `null` / `false`.
 *
 * @mixin PlanFeatureLimit
 */
class PlanFeatureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->feature->value,
            'label' => $this->feature->label(),
            'unlimited' => $this->feature->isLimitable() && $this->is_unlimited,
            'limit' => $this->feature->isLimitable() && ! $this->is_unlimited ? $this->limit : null,
        ];
    }
}
