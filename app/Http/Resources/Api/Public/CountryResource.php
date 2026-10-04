<?php

namespace App\Http\Resources\Api\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nnjeim\World\Models\Country;

/**
 * Minimal registration-dropdown shape. The package row carries much more
 * (timezones, currencies, translations); none of it is exposed here.
 *
 * @mixin Country
 */
class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'iso2' => $this->iso2,
        ];
    }
}
