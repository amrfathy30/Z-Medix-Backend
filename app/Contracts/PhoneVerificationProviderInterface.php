<?php

namespace App\Contracts;

interface PhoneVerificationProviderInterface
{
    /**
     * Initiate an OTP verification for the given phone number.
     *
     * Returns a normalized result array:
     * [
     *   'provider_reference' => string,  // Provider-specific ID (e.g. Twilio SID)
     *   'status'             => string,  // e.g. 'pending'
     *   'channel'            => string,  // e.g. 'sms'
     * ]
     *
     * @param  array<string, mixed>  $metadata  Optional extra data (request IP, user agent, etc.)
     * @return array<string, mixed>
     */
    public function sendVerification(string $e164, string $channel, string $purpose, array $metadata = []): array;

    /**
     * Check a user-submitted OTP code against the provider.
     *
     * Returns a normalized result array:
     * [
     *   'valid'  => bool,    // Whether the code was correct and approved
     *   'status' => string,  // Provider status string (e.g. 'approved', 'pending', 'canceled')
     * ]
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function checkVerification(string $e164, string $code, string $purpose, array $metadata = []): array;
}
