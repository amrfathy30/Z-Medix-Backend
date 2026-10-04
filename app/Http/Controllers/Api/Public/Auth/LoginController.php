<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\LoginRequest;
use App\Http\Resources\Api\UserProfileResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    use ApiResponse;

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->errorResponse(
                'The provided credentials are incorrect.',
                [],
                Response::HTTP_UNAUTHORIZED,
                'INVALID_CREDENTIALS',
            );
        }

        if ($user->status === AccountStatus::Suspended || $user->status === AccountStatus::Blocked || $user->status === AccountStatus::Inactive) {
            return $this->errorResponse(
                'Your account has been suspended. Please contact support.',
                [],
                Response::HTTP_FORBIDDEN,
                'ACCOUNT_SUSPENDED',
            );
        }

        // Email verification is the only verification that gates platform access.
        // Phone verification deliberately does not block login. Checked before the
        // status gate so an unverified account always receives the code the client
        // can act on.
        if (! $user->hasVerifiedEmail()) {
            return $this->errorResponse(
                'Your email address is not verified. Please verify your email address to continue.',
                [],
                Response::HTTP_FORBIDDEN,
                'EMAIL_NOT_VERIFIED',
            );
        }

        // Login requires an active account. Pending accounts are rejected even once
        // the email is verified: only the OTP activation flow promotes an account to
        // active, and any status other than active is not eligible.
        if ($user->status !== AccountStatus::Active) {
            return $this->errorResponse(
                'Your account is not active. Please contact support.',
                [],
                Response::HTTP_FORBIDDEN,
                'ACCOUNT_NOT_ACTIVE',
            );
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('api')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => new UserProfileResource($user),
        ], 'Logged in successfully.');
    }
}
