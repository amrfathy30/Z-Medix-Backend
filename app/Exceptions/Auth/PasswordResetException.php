<?php

namespace App\Exceptions\Auth;

use Exception;
use Illuminate\Http\Response;

/**
 * Raised by PasswordResetService for reset-token failures. Carries the HTTP
 * status and the stable machine-readable identifier the public API surfaces.
 */
class PasswordResetException extends Exception
{
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidToken(): self
    {
        return new self(
            'The password reset token is invalid.',
            'RESET_TOKEN_INVALID',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function expiredToken(): self
    {
        return new self(
            'The password reset token has expired. Please start again.',
            'RESET_TOKEN_EXPIRED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function usedToken(): self
    {
        return new self(
            'The password reset token has already been used. Please start again.',
            'RESET_TOKEN_USED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function accountNotActive(): self
    {
        return new self(
            'Your account is not active. Please contact support.',
            'ACCOUNT_NOT_ACTIVE',
            Response::HTTP_FORBIDDEN,
        );
    }

    public static function accountSuspended(): self
    {
        return new self(
            'Your account has been suspended. Please contact support.',
            'ACCOUNT_SUSPENDED',
            Response::HTTP_FORBIDDEN,
        );
    }
}
