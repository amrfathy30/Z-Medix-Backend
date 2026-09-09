<?php

return [

    'login' => [
        /*
         * Controls whether users with "pending" account status may log in.
         * Suspended and blocked users are always denied regardless of this setting.
         */
        'allow_pending_users' => env('AUTH_ALLOW_PENDING_LOGIN', true),
    ],

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
    ],

    'email_verification' => [
        /*
         * Frontend redirect paths after email verification attempt.
         * These are appended to FRONTEND_URL.
         */
        'success_path' => env('AUTH_EMAIL_VERIFIED_SUCCESS_PATH', '/email/verified?status=success'),
        'failed_path' => env('AUTH_EMAIL_VERIFIED_FAILED_PATH', '/email/verified?status=failed'),
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
