<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Exceptions\Auth\PasswordResetException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\ResetPasswordRequest;
use App\Services\Auth\PasswordResetService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Consumes a single-use reset token and sets the new password.
 */
class ResetPasswordController extends Controller
{
    use ApiResponse;

    public function __invoke(ResetPasswordRequest $request, PasswordResetService $passwordReset): JsonResponse
    {
        try {
            $passwordReset->resetPassword(
                $request->string('reset_token')->value(),
                $request->string('password')->value(),
            );
        } catch (PasswordResetException $e) {
            return $this->errorResponse($e->getMessage(), [], $e->status, $e->errorCode);
        }

        return $this->successResponse(null, 'Your password has been reset. Please sign in with your new password.');
    }
}
