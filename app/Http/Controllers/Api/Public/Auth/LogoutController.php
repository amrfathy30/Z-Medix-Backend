<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Http\Controllers\Controller;
use App\Services\Notifications\DeviceTokenRegistrar;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    use ApiResponse;

    public function __construct(private DeviceTokenRegistrar $registrar) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fcm_token' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();

        if ($user !== null) {
            $this->registrar->release(
                owner: $user,
                deviceId: $validated['device_id'] ?? null,
                token: $validated['fcm_token'] ?? null,
            );

            $user->currentAccessToken()?->delete();
        }

        return $this->successResponse(null, 'Logged out successfully.');
    }
}

