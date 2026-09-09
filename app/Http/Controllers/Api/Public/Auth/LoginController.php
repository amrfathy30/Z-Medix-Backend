<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\LoginRequest;
use App\Http\Resources\Api\UserProfileResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    use ApiResponse;

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->errorResponse('The provided credentials are incorrect.', [], 401);
        }

        if ($user->status === AccountStatus::Suspended || $user->status === AccountStatus::Blocked || $user->status === AccountStatus::Inactive) {
            return $this->errorResponse('Your account has been suspended. Please contact support.', [], 403);
        }

        if ($user->status === AccountStatus::Pending && ! config('auth_features.login.allow_pending_users')) {
            return $this->errorResponse('Your account is pending approval. Please check back later.', [], 403);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('api')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'user' => new UserProfileResource($user),
        ], 'Logged in successfully.');
    }
}
