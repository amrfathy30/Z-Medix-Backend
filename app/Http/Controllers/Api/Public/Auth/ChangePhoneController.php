<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Exceptions\Phone\InvalidPhoneException;
use App\Exceptions\Phone\PhoneAlreadyExistsException;
use App\Exceptions\Phone\VerificationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\ChangePhoneRequest;
use App\Http\Requests\Api\Public\Auth\SendPhoneOtpRequest;
use App\Http\Requests\Api\Public\Auth\VerifyPhoneOtpRequest;
use App\Models\User;
use App\Services\Phone\ChangePhoneService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChangePhoneController extends Controller
{
    use ApiResponse;

    public function store(ChangePhoneRequest $request, ChangePhoneService $changes): JsonResponse
    {
        return $this->send($request, $changes, $request->validated('phone'));
    }

    public function resend(SendPhoneOtpRequest $request, ChangePhoneService $changes): JsonResponse
    {
        return $this->send($request, $changes);
    }

    public function verify(VerifyPhoneOtpRequest $request, ChangePhoneService $changes): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $phone = $changes->verify($user, $request->code(), $this->metadata($request));
        } catch (VerificationException|InvalidPhoneException|PhoneAlreadyExistsException $e) {
            return $this->failure($e);
        }

        return $this->successResponse([
            'phone' => $phone->e164_number,
            'phone_verified' => true,
        ], 'Phone number changed successfully.');
    }

    private function send(SendPhoneOtpRequest $request, ChangePhoneService $changes, ?string $phone = null): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $otp = $changes->send($user, $phone, $request->channel(), $this->metadata($request));
        } catch (VerificationException|InvalidPhoneException|PhoneAlreadyExistsException $e) {
            return $this->failure($e);
        }

        return $this->successResponse([
            'phone' => $otp->target_phone,
            'phone_verified' => false,
            'expires_at' => $otp->expires_at->toIso8601String(),
        ], 'We have sent a verification code to your new phone number.');
    }

    private function failure(VerificationException|InvalidPhoneException|PhoneAlreadyExistsException $e): JsonResponse
    {
        if ($e instanceof VerificationException) {
            return $this->errorResponse($e->getMessage(), [], $e->status, $e->errorCode);
        }

        return $this->errorResponse(
            $e->getMessage(),
            ['phone' => [$e->getMessage()]],
            422,
            $e instanceof InvalidPhoneException ? 'INVALID_PHONE' : 'PHONE_ALREADY_REGISTERED',
        );
    }

    /** @return array{ip_address: string|null, user_agent: string|null} */
    private function metadata(Request $request): array
    {
        return ['ip_address' => $request->ip(), 'user_agent' => $request->userAgent()];
    }
}
