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
