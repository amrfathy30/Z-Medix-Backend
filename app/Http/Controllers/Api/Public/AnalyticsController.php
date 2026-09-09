<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\GoogleAnalyticsSetting;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The Google Analytics (GA4) settings the frontend needs to initialise
 * gtag.js.
 *
 * Unauthenticated and deliberately narrow: it returns `enabled`/
 * `measurement_id` and nothing else. `api_secret`/`property_id`/
 * `service_account_json` authorise server-side calls this backend makes
 * itself and are never selected here.
 */
class AnalyticsController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $setting = GoogleAnalyticsSetting::current();

        return $this->successResponse([
            'enabled' => $setting->enabled,
            'measurement_id' => $setting->measurement_id,
        ]);
    }
}
