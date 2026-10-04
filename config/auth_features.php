<?php

return [

    'password_reset' => [
        /*
         * The base URL of the frontend application.
         * Used to construct email links for password reset and email verification.
         */
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

        /*
         * The frontend path appended to frontend_url for the reset-password page.
         * Resulting link: {frontend_url}{path}?token={token}&email={email}
         */
        'path' => env('AUTH_PASSWORD_RESET_PATH', '/reset-password'),

        /*
         * How many minutes a password reset token stays usable after the student
         * verifies their reset OTP. Tokens are single-use.
         */
        'token_ttl_minutes' => (int) env('AUTH_PASSWORD_RESET_TOKEN_TTL_MINUTES', 15),
    ],

    'email_verification' => [
        /*
         * Frontend redirect paths after email verification attempt.
         * These are appended to FRONTEND_URL.
         */
        'success_path' => env('AUTH_EMAIL_VERIFIED_SUCCESS_PATH', '/email/verified?status=success'),
        'failed_path' => env('AUTH_EMAIL_VERIFIED_FAILED_PATH', '/email/verified?status=failed'),
    ],

    'email_otp' => [
        /*
         * Number of numeric digits in a generated email verification code.
         */
        'length' => (int) env('AUTH_EMAIL_OTP_LENGTH', 6),

        /*
         * How many minutes a pending code remains usable.
         */
        'expiry_minutes' => (int) env('AUTH_EMAIL_OTP_EXPIRY_MINUTES', 10),

        /*
         * How many times a single code may be submitted before it is burned.
         */
        'max_attempts' => (int) env('AUTH_EMAIL_OTP_MAX_ATTEMPTS', 5),

        /*
         * Minimum number of seconds between two code deliveries for the same
         * email address and client IP.
         */
        'resend_cooldown_seconds' => (int) env('AUTH_EMAIL_OTP_RESEND_COOLDOWN', 60),

        /*
         * Maximum number of code deliveries per rolling hour for the same email
         * address and client IP. The delivery sent during registration counts
         * towards this allowance.
         */
        'max_sends_per_hour' => (int) env('AUTH_EMAIL_OTP_MAX_SENDS_PER_HOUR', 5),

        /*
         * Fixed code accepted in place of the real one, for manual testing on
         * non-production environments. Leave unset to disable the behaviour.
         *
         * It only replaces the code comparison: a real, unexpired pending OTP with
         * attempts remaining must still exist, and the attempt counter still moves.
         *
         * Env: AUTH_TEST_OTP_CODE
         */
        'test_code' => env('AUTH_TEST_OTP_CODE'),

        /*
         * Environments in which 'test_code' is honoured. Both this list and the
         * code itself must be set, otherwise the behaviour stays disabled.
         *
         * 'production' is refused even when it appears here.
         *
         * Env: AUTH_TEST_OTP_ENVIRONMENTS (comma-separated, e.g. "local,testing,staging")
         */
        'test_code_environments' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('AUTH_TEST_OTP_ENVIRONMENTS', '')))
        )),
    ],

    'registration' => [
        /*
         * ISO 3166-1 alpha-2 country codes accepted for the phone number supplied
         * during student registration. An empty array accepts every country that
         * libphonenumber can parse, which is the documented product behaviour —
         * students are not restricted to the MENA list in config/phone.php that
         * governs other phone flows.
         *
         * Env: AUTH_REGISTRATION_PHONE_COUNTRIES (comma-separated, e.g. "EG,SA,AE")
         */
        'allowed_phone_countries' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('AUTH_REGISTRATION_PHONE_COUNTRIES', '')))
        )),
    ],

    'super_admin' => [
        /*
         * Seed-time defaults for the super admin account created by
         * SuperAdminSeeder. Protection of super admin accounts is enforced
         * by AdminType::SuperAdmin, not by comparing against this email.
         */
        'email' => env('SUPER_ADMIN_EMAIL', 'admin@pulvent.com'),
        'password' => env('SUPER_ADMIN_PASSWORD', 'password'),
    ],

];
