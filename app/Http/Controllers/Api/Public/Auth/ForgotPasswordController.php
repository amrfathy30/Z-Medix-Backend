<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\ForgotPasswordRequest;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    use ApiResponse;

    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker('users')->sendResetLink(
            $request->only('email')
        );

        // Always return a generic response to avoid exposing whether the email exists.
        return $this->successResponse(null, 'If that email address is in our system, we have sent a password reset link.');
    }
}
