<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\FcmTokenStoreRequest;
use App\Models\FcmToken;
use App\Services\Notifications\DeviceTokenRegistrar;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FcmTokenController extends Controller
{
    use ApiResponse;

    public function __construct(private DeviceTokenRegistrar $registrar) {}

    /**
     * Register or refresh a device token.
     */
    public function store(FcmTokenStoreRequest $request): JsonResponse
    {
        $token = $this->registrar->register(
            owner: $request->user(),
            token: $request->validated('token'),
            deviceType: $request->validated('device_type'),
            deviceId: $request->validated('device_id'),
            appType: $request->validated('app_type'),
        );

        return $this->successResponse([
            'id' => $token->id,
            'token' => $token->token,
        ], __('notifications.token_registered'), 201);
    }

    /**
     * Remove a device token. A user may only delete their own token.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        $user = $request->user();

        $token = FcmToken::where('token_hash', FcmToken::hashFor($request->string('token')->toString()))
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->first();

        if ($token === null) {
            return $this->errorResponse(__('notifications.token_not_found'), [], 404);
        }

        $token->delete();

        return $this->successResponse(null, __('notifications.token_removed'));
    }
}
