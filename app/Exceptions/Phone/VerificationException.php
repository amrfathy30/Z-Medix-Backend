<?php

namespace App\Exceptions\Phone;

use RuntimeException;

class VerificationException extends RuntimeException
{
    public static function noPendingOtp(): self
    {
        return new self('No active pending OTP found for this phone number and purpose.');
    }

    public static function expired(): self
    {
        return new self('The OTP has expired. Please request a new one.');
    }

    public static function tooManyAttempts(): self
    {
        return new self('Maximum verification attempts exceeded. Please request a new OTP.');
    }

    public static function unsupportedChannel(string $channel): self
    {
        return new self("The channel '{$channel}' is not supported.");
    }

    public static function providerError(string $message): self
    {
        return new self("Verification provider error: {$message}");
    }
}
