<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Public\SettingResource;
use App\Models\Setting;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $settings = Setting::where('is_public', true)->get();

        $grouped = $settings->groupBy('group')->map(
            fn ($group) => SettingResource::collection($group)
        );

        return $this->successResponse($grouped);
    }
}
