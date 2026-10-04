<?php

namespace App\Exceptions\Auth;

use Exception;
use Illuminate\Http\Response;

/**
 * Raised by EmailOtpService. Carries the HTTP status and the stable
 * machine-readable identifier the public API should surface to clients.
 */
class EmailOtpException extends Exception
{
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidCode(): self
    {
        return new self(
            'The verification code is invalid.',
            'OTP_INVALID',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function noPendingOtp(): self
    {
        return new self(
            'No active verification code was found. Please request a new one.',
            'OTP_NOT_FOUND',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function expired(): self
    {
        return new self(
            'The verification code has expired. Please request a new one.',
            'OTP_EXPIRED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function tooManyAttempts(): self
    {
        return new self(
            'Maximum verification attempts exceeded. Please request a new code.',
            'OTP_MAX_ATTEMPTS_EXCEEDED',
            Response::HTTP_TOO_MANY_REQUESTS,
        );
    }

    public static function cooldownActive(int $secondsRemaining): self
    {
        return new self(
            "Please wait {$secondsRemaining} seconds before requesting another verification code.",
            'OTP_RESEND_COOLDOWN',
            Response::HTTP_TOO_MANY_REQUESTS,
        );
    }

    public static function sendLimitReached(int $secondsRemaining): self
    {
        return new self(
            "Too many verification codes requested. Please try again in {$secondsRemaining} seconds.",
            'OTP_SEND_LIMIT_REACHED',
            Response::HTTP_TOO_MANY_REQUESTS,
        );
    }
}
