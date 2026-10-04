<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\Auth\EmailOtpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\ForgotPasswordRequest;
use App\Services\Auth\EmailOtpService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Re-issues a password reset passcode, invalidating any previous pending one.
 */
class ResendPasswordResetOtpController extends Controller
{
    use ApiResponse;

    public function __invoke(ForgotPasswordRequest $request, EmailOtpService $emailOtp): JsonResponse
    {
        try {
            $emailOtp->resend($request->string('email')->value(), OtpPurpose::PasswordReset, [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (EmailOtpException $e) {
            return $this->errorResponse($e->getMessage(), [], $e->status, $e->errorCode);
        }

        return $this->successResponse(
            null,
            'If that email address is in our system, we have sent a password reset code.',
        );
    }
}
