<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Exceptions\Auth\EmailOtpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\VerifyEmailOtpRequest;
use App\Http\Resources\Api\UserProfileResource;
use App\Models\User;
use App\Services\Auth\EmailOtpService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Consumes an email verification code: marks the email verified and activates
 * the account in a single transaction.
 */
class VerifyEmailOtpController extends Controller
{
    use ApiResponse;

    public function __invoke(VerifyEmailOtpRequest $request, EmailOtpService $emailOtp): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->value())->first();

        // An unknown address is answered exactly like a wrong code so the
        // endpoint cannot be used to discover registered email addresses.
        if ($user === null) {
            $invalid = EmailOtpException::invalidCode();

            return $this->errorResponse($invalid->getMessage(), [], $invalid->status, $invalid->errorCode);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->successResponse(null, 'Email address is already verified.');
        }

        try {
            $emailOtp->verifyAndActivate($user, $request->string('code')->value());
        } catch (EmailOtpException $e) {
            return $this->errorResponse($e->getMessage(), [], $e->status, $e->errorCode);
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => new UserProfileResource($user->refresh()),
        ], 'Email verified successfully. Your account is now active.', Response::HTTP_OK);
    }
}
