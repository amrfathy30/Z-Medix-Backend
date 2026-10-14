<?php

namespace App\Http\Resources\Api\Student;

use App\Models\FellowshipFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One dynamic filter a student may narrow a session by, with its values.
 *
 * `is_performance` marks the Fellowship's required classification. It is offered
 * for narrowing like any other filter, and flagged so the frontend can give it
 * the prominence the Fellowship screens give it elsewhere.
 *
 * Choosing several values within one filter widens the pool; choosing values in
 * more than one filter narrows it. Leaving a filter alone means All.
 *
 * @mixin FellowshipFilter
 */
class FellowshipFilterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_performance' => (bool) $this->is_performance,
            'values' => $this->values->map(fn ($value): array => [
                'id' => $value->id,
                'value' => $value->value,
            ])->values()->all(),
        ];
    }
}
