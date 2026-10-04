<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Exceptions\Auth\EmailOtpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\ResendEmailOtpRequest;
use App\Services\Auth\EmailOtpService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Re-issues an email verification code, invalidating any previous pending code.
 */
class ResendEmailOtpController extends Controller
{
    use ApiResponse;

    public function __invoke(ResendEmailOtpRequest $request, EmailOtpService $emailOtp): JsonResponse
    {
        try {
            $emailOtp->resend($request->string('email')->value(), metadata: [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (EmailOtpException $e) {
            return $this->errorResponse($e->getMessage(), [], $e->status, $e->errorCode);
        }

        // Deliberately generic: the same response is returned whether or not the
        // address belongs to an account awaiting verification.
        return $this->successResponse(
            null,
            'If that email address is awaiting verification, we have sent a new verification code.',
        );
    }
}
