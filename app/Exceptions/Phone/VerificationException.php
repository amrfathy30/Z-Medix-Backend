<?php

namespace App\Exceptions\Phone;

use Illuminate\Http\Response;
use RuntimeException;

/**
 * Raised by PhoneNumberService. Carries the HTTP status and the stable
 * machine-readable identifier the public API should surface to clients.
 *
 * Provider failures are deliberately split in two: `providerError()` keeps the
 * raw upstream text for logs and never reaches a client, while
 * `deliveryFailed()` is the generic message the API returns in its place.
 */
class VerificationException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $errorCode = 'PHONE_VERIFICATION_FAILED',
        public readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self('Phone verification is currently disabled.', 'PHONE_OTP_DISABLED', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    public static function alreadyVerified(): self
    {
        return new self('This phone number is already verified.', 'PHONE_ALREADY_VERIFIED');
    }

    public static function noPendingOtp(): self
    {
        return new self(
            'No active verification code was found. Please request a new one.',
            'PHONE_OTP_NOT_FOUND',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function expired(): self
    {
        return new self(
            'The verification code has expired. Please request a new one.',
            'PHONE_OTP_EXPIRED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function tooManyAttempts(): self
    {
        return new self(
            'Maximum verification attempts exceeded. Please request a new code.',
            'PHONE_OTP_MAX_ATTEMPTS_EXCEEDED',
            Response::HTTP_TOO_MANY_REQUESTS,
        );
    }

    public static function invalidCode(): self
    {
        return new self(
            'The verification code is invalid.',
            'PHONE_OTP_INVALID',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function cooldownActive(int $secondsRemaining): self
    {
        return new self(
            "Please wait {$secondsRemaining} seconds before requesting another verification code.",
            'PHONE_OTP_RESEND_COOLDOWN',
            Response::HTTP_TOO_MANY_REQUESTS,
        );
    }

    public static function sendLimitReached(int $secondsRemaining): self
    {
        return new self(
            "Too many verification codes requested. Please try again in {$secondsRemaining} seconds.",
            'PHONE_OTP_SEND_LIMIT_REACHED',
            Response::HTTP_TOO_MANY_REQUESTS,
        );
    }

    public static function unsupportedChannel(string $channel): self
    {
        return new self(
            "The channel '{$channel}' is not supported.",
            'PHONE_CHANNEL_UNSUPPORTED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function noPhoneOnRecord(): self
    {
        return new self(
            'No phone number is on record for this account. Please add one first.',
            'PHONE_NOT_FOUND',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * The upstream provider's own text. Callers log this; the public API answers
     * with deliveryFailed() instead so provider internals are never disclosed.
     */
    public static function providerError(string $message): self
    {
        return new self(
            "Verification provider error: {$message}",
            'PHONE_PROVIDER_ERROR',
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * The client-safe replacement for any provider failure.
     */
    public static function deliveryFailed(): self
    {
        return new self(
            'We could not send a verification code right now. Please try again shortly.',
            'PHONE_OTP_SEND_FAILED',
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
