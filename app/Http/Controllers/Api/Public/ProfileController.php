<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\ProfileUpdateRequest;
use App\Http\Resources\Api\UserProfileResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(new UserProfileResource($request->user()));
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $emailChanged = isset($validated['email']) && $validated['email'] !== $user->email;

        if ($emailChanged) {
            $validated['email_verified_at'] = null;
        }

        $user->forceFill($validated)->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $this->successResponse(new UserProfileResource($user->fresh()), 'Profile updated successfully.');
    }
}
