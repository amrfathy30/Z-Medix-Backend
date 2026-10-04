<?php

namespace App\Http\Resources\Api\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nnjeim\World\Models\City;

/**
 * Minimal registration-dropdown shape.
 *
 * @mixin City
 */
class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'country_id' => $this->country_id,
        ];
    }
}
