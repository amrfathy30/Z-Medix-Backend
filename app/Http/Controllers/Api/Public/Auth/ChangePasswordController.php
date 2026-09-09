<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\ChangePasswordRequest;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class ChangePasswordController extends Controller
{
    use ApiResponse;

    public function __invoke(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['password' => Hash::make($request->password)])->save();

        // Revoke all tokens except the current one so the user stays logged in on this device.
        $currentTokenId = $user->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return $this->successResponse(null, 'Password changed successfully.');
    }
}
