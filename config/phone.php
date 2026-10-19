<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Country
    |--------------------------------------------------------------------------
    |
    | The default ISO 3166-1 alpha-2 country code used when no country is
    | specified during phone number parsing.
    |
    | Env: PHONE_DEFAULT_COUNTRY
    |
    */

    'default_country' => env('PHONE_DEFAULT_COUNTRY', 'EG'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Countries
    |--------------------------------------------------------------------------
    |
    | ISO 3166-1 alpha-2 country codes that are accepted. An empty array means
    | all countries are allowed. Add to this list to restrict registration.
    |
    | Env: PHONE_ALLOWED_COUNTRIES (comma-separated, e.g. "EG,SA,AE")
    |
    */

    'allowed_countries' => array_filter(
        array_map('trim', explode(',', env('PHONE_ALLOWED_COUNTRIES', 'EG,SA,AE,KW,QA,BH,OM,JO,LB,IQ,LY,TN,MA,SD')))
    ),

    /*
    |--------------------------------------------------------------------------
    | Global Uniqueness
    |--------------------------------------------------------------------------
    |
    | When true, the same E.164 phone number cannot be registered by two
    | different owners (across all owner types). When false, the same number
    | may appear under multiple owners.
    |
    | Env: PHONE_UNIQUE_GLOBALLY
    |
    */

    'unique_globally' => (bool) env('PHONE_UNIQUE_GLOBALLY', true),

    /*
    |--------------------------------------------------------------------------
    | Max Phone Numbers Per Owner
    |--------------------------------------------------------------------------
    |
    | The maximum number of active (non-deleted) phone numbers a single owner
    | is allowed to have.
    |
    | Env: PHONE_MAX_PER_OWNER
    |
    */

    'max_numbers_per_owner' => (int) env('PHONE_MAX_PER_OWNER', 3),

    /*
    |--------------------------------------------------------------------------
    | Default Channel
    |--------------------------------------------------------------------------
    |
    | The channel used when no channel is specified for OTP delivery.
    | Currently supported: sms
    | Planned: whatsapp, call
    |
    */

    'default_channel' => env('PHONE_DEFAULT_CHANNEL', 'sms'),

    /*
    |--------------------------------------------------------------------------
    | Supported Channels
    |--------------------------------------------------------------------------
    |
    | Channels the service will accept. Attempting to use an unlisted channel
    | will throw an exception.
    |
    */

    'supported_channels' => ['sms'],

    /*
    |--------------------------------------------------------------------------
    | OTP Expiry
    |--------------------------------------------------------------------------
    |
    | How many minutes before a pending OTP record is considered expired.
    | Note: the external provider (e.g. Twilio Verify) also enforces its own
    | expiry independently of this value.
    |
    | Env: PHONE_OTP_EXPIRY_MINUTES
    |
    */

    'otp_expiry_minutes' => (int) env('PHONE_OTP_EXPIRY_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | OTP Attempt Limit
    |--------------------------------------------------------------------------
    |
    | How many times a single OTP may be submitted before it is burned. The
    | external provider enforces its own attempt ceiling independently; this is
    | the local limit recorded against each phone_verification_otps row.
    |
    | Env: PHONE_OTP_MAX_ATTEMPTS
    |
    */

    'otp_max_attempts' => (int) env('PHONE_OTP_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Resend Cooldown
    |--------------------------------------------------------------------------
    |
    | Minimum number of seconds between two OTP deliveries for the same phone
    | number, purpose and client IP. Every send is billable, so this is the
    | first line of defence against SMS cost abuse.
    |
    | Env: PHONE_OTP_RESEND_COOLDOWN
    |
    */

    'otp_resend_cooldown_seconds' => (int) env('PHONE_OTP_RESEND_COOLDOWN', 60),

    /*
    |--------------------------------------------------------------------------
    | Hourly Send Allowance
    |--------------------------------------------------------------------------
    |
    | Maximum number of OTP deliveries per rolling hour for the same phone
    | number, purpose and client IP.
    |
    | Env: PHONE_OTP_MAX_SENDS_PER_HOUR
    |
    */

    'otp_max_sends_per_hour' => (int) env('PHONE_OTP_MAX_SENDS_PER_HOUR', 5),

    /*
    |--------------------------------------------------------------------------
    | Test OTP Code
    |--------------------------------------------------------------------------
    |
    | Fixed code accepted in place of the real one, for manual testing on
    | non-production environments. Leave unset to disable the behaviour.
    |
    | It replaces the provider check only: a real, unexpired pending OTP with
    | attempts remaining must still exist, and the attempt counter still moves.
    | When it matches, Twilio Verify is not contacted at all.
    |
    | Mirrors auth_features.email_otp.test_code, and is scoped separately so a
    | phone test code never implies an email one.
    |
    | Env: PHONE_TEST_OTP_CODE
    |
    */

    'otp_test_code' => env('PHONE_TEST_OTP_CODE'),

    /*
    |--------------------------------------------------------------------------
    | Test OTP Environments
    |--------------------------------------------------------------------------
    |
    | Environments in which 'otp_test_code' is honoured. Both this list and the
    | code itself must be set, otherwise the behaviour stays disabled.
    |
    | 'production' is refused even when it appears here.
    |
    | Env: PHONE_TEST_OTP_ENVIRONMENTS (comma-separated, e.g. "local,testing")
    |
    */

    'otp_test_code_environments' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('PHONE_TEST_OTP_ENVIRONMENTS', '')))
    )),

    /*
    |--------------------------------------------------------------------------
    | Default Verification Provider
    |--------------------------------------------------------------------------
    |
    | The provider key used for OTP delivery. Must match a key under
    | the 'providers' array below. The service container will resolve the
    | PhoneVerificationProviderInterface to the configured implementation.
    |
    | Env: PHONE_VERIFICATION_PROVIDER
    |
    */

    'default_provider' => env('PHONE_VERIFICATION_PROVIDER', 'twilio_verify'),

    /*
    |--------------------------------------------------------------------------
    | Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Credentials and settings for each supported verification provider.
    |
    | Twilio Verify env keys:
    |   TWILIO_ACCOUNT_SID         - Your Twilio Account SID (ACxxxxxxxxxxxxxxxx)
    |   TWILIO_AUTH_TOKEN          - Your Twilio Auth Token
    |   TWILIO_VERIFY_SERVICE_SID  - Your Verify Service SID (VAxxxxxxxxxxxxxxxx)
    |
    */

    'providers' => [
        'twilio_verify' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'verify_service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
        ],
    ],

];
