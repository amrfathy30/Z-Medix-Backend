<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Exceptions\Auth\EmailOtpException;
use App\Exceptions\Auth\PasswordResetException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\VerifyPasswordResetOtpRequest;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Consumes a password reset passcode and hands back a short-lived reset token.
 *
 * Verification here never alters the account: no email verification timestamp, no
 * status change, no Verified event.
 */
class VerifyPasswordResetOtpController extends Controller
{
    use ApiResponse;

    public function __invoke(VerifyPasswordResetOtpRequest $request, PasswordResetService $passwordReset): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->value())->first();

        // An unknown address is answered exactly like a wrong code so the endpoint
        // cannot be used to discover registered email addresses.
        if ($user === null) {
            $invalid = EmailOtpException::invalidCode();

            return $this->errorResponse($invalid->getMessage(), [], $invalid->status, $invalid->errorCode);
        }

        try {
            $resetToken = $passwordReset->verifyOtpAndIssueToken(
                $user,
                $request->string('code')->value(),
                [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );
        } catch (EmailOtpException|PasswordResetException $e) {
            return $this->errorResponse($e->getMessage(), [], $e->status, $e->errorCode);
        }

        return $this->successResponse([
            'reset_token' => $resetToken,
        ], 'Verification successful. Use the reset token to set a new password.');
    }
}
