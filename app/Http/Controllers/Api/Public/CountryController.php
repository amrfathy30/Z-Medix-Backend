<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Public\CityResource;
use App\Http\Resources\Api\Public\CountryResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Country/City lookups for the Lecturer registration form, backed by the
 * nnjeim/world package tables. Always paginated in SQL — the full dataset is
 * never loaded or returned.
 */
class CountryController extends Controller
{
    use ApiResponse;

    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    /**
     * List countries
     *
     * Paginated country lookup for registration dropdowns. Supports `search`
     * (name), `page` and `per_page` (default 20, maximum 100).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $query = Country::query()
            ->where('status', 1)
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where('name', 'LIKE', "%{$search}%");
        }

        $countries = $query->get(['id','name','iso2']);


        return $this->successResponse(CountryResource::collection($countries));
    }

    /**
     * List cities of a country
     *
     * Paginated city lookup scoped to one country. Supports `search` (name),
     * `page` and `per_page` (default 20, maximum 100). An unknown country
     * returns 404.
     */
    public function cities(Request $request, Country $country): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $query = City::query()
            ->where('country_id', $country->id)
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where('name', 'LIKE', "%{$search}%");
        }

        $cities = $query->get(['id','name','country_id']);

        return $this->successResponse(CityResource::collection($cities));
    }
}
