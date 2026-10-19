<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Enums\PhoneStatus;
use App\Exceptions\Phone\VerificationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\VerifyPhoneOtpRequest;
use App\Http\Resources\Api\UserProfileResource;
use App\Models\User;
use App\Services\Phone\PhoneNumberService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Consumes a phone verification code for the authenticated student's primary
 * phone number.
 *
 * Verifying a phone number grants no additional access — it only flips
 * `phone_verified` on the profile. The number is resolved from the
 * authenticated user, so a code can never be applied to someone else's phone.
 */
class VerifyPhoneOtpController extends Controller
{
    use ApiResponse;

    public function __invoke(VerifyPhoneOtpRequest $request, PhoneNumberService $phoneNumbers): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $phone = $user->primaryPhoneNumber();

        if ($phone === null) {
            $missing = VerificationException::noPhoneOnRecord();

            return $this->errorResponse($missing->getMessage(), [], $missing->status, $missing->errorCode);
        }

        if ($phone->status === PhoneStatus::Verified) {
            return $this->successResponse([
                'phone_verified' => true,
                'user' => new UserProfileResource($user->fresh()),
            ], 'This phone number is already verified.');
        }

        try {
            $verified = $phoneNumbers->verifyPhone(
                $phone,
                $request->code(),
                metadata: [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );
        } catch (VerificationException $e) {
            return $this->errorResponse($e->getMessage(), [], $e->status, $e->errorCode);
        }

        if (! $verified) {
            $invalid = VerificationException::invalidCode();

            return $this->errorResponse($invalid->getMessage(), [], $invalid->status, $invalid->errorCode);
        }

        return $this->successResponse([
            'phone_verified' => true,
            'user' => new UserProfileResource($user->fresh()),
        ], 'Phone number verified successfully.');
    }
}
