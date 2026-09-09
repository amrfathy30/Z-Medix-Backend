<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingPixelService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The tracking pixel IDs the frontend installs.
 *
 * Unauthenticated and deliberately narrow: it returns the pixel IDs and
 * nothing else. It shares no code and no cache with the public settings
 * endpoint, so neither can ever start publishing the other's data.
 */
class MarketingPixelController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MarketingPixelService $pixels) {}

    /**
     * Every switched-on platform that holds an ID, keyed by code. A
     * switched-off platform is absent from `data` entirely — it is never
     * rendered as a null value.
     */
    public function index(): JsonResponse
    {
        return $this->successResponse((object) $this->pixels->publicPixels());
    }
}
