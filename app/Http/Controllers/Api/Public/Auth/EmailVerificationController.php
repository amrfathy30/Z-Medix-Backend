<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmailVerificationController extends Controller
{
    use ApiResponse;

    public function send(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $this->successResponse(null, 'Email address is already verified.');
        }

        $request->user()->sendEmailVerificationNotification();

        return $this->successResponse(null, 'Verification email sent.');
    }

    public function verify(Request $request, int $id, string $hash): JsonResponse|RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->failedRedirectOrJson($request, 'Invalid or expired verification link.');
        }

        $user = User::find($id);

        if (! $user || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return $this->failedRedirectOrJson($request, 'Invalid verification link.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $this->successRedirectOrJson($request);
    }

    private function successRedirectOrJson(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return $this->successResponse(null, 'Email verified successfully.');
        }

        return redirect($this->frontendUrl('success_path'));
    }

    private function failedRedirectOrJson(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return $this->errorResponse($message, [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return redirect($this->frontendUrl('failed_path'));
    }

    private function frontendUrl(string $pathKey): string
    {
        $base = rtrim((string) config('auth_features.password_reset.frontend_url', 'http://localhost:3000'), '/');
        $path = config("auth_features.email_verification.{$pathKey}", '/email/verified?status=success');

        return $base.$path;
    }
}
