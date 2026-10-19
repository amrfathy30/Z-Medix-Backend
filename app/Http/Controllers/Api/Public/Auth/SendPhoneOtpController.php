<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Enums\PhoneStatus;
use App\Exceptions\Phone\VerificationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\SendPhoneOtpRequest;
use App\Models\User;
use App\Services\Phone\PhoneNumberService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Sends a verification code to the authenticated student's primary phone number.
 *
 * Phone verification is optional: nothing on the platform is gated on it, and
 * this endpoint exists purely so a student who wants a verified number can get
 * one. The number is always resolved from the authenticated user, never from
 * the request body.
 */
class SendPhoneOtpController extends Controller
{
    use ApiResponse;

    public function __invoke(SendPhoneOtpRequest $request, PhoneNumberService $phoneNumbers): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $phone = $user->primaryPhoneNumber();

        if ($phone === null) {
            $missing = VerificationException::noPhoneOnRecord();

            return $this->errorResponse($missing->getMessage(), [], $missing->status, $missing->errorCode);
        }

        // Already-verified numbers are answered successfully rather than as an
        // error: the caller's desired end state already holds, and re-sending
        // would be a billable no-op.
        if ($phone->status === PhoneStatus::Verified) {
            return $this->successResponse([
                'phone' => $phone->e164_number,
                'phone_verified' => true,
            ], 'This phone number is already verified.');
        }

        try {
            $otp = $phoneNumbers->requestVerification(
                $phone,
                channel: $request->channel(),
                metadata: [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );
        } catch (VerificationException $e) {
            return $this->errorResponse($e->getMessage(), [], $e->status, $e->errorCode);
        }

        return $this->successResponse([
            'phone' => $phone->e164_number,
            'phone_verified' => false,
            'expires_at' => $otp->expires_at?->toIso8601String(),
        ], 'We have sent a verification code to your phone number.');
    }
}
