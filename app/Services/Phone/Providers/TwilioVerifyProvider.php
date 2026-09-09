<?php

namespace App\Services\Phone\Providers;

use App\Contracts\PhoneVerificationProviderInterface;
use App\Exceptions\Phone\VerificationException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

class TwilioVerifyProvider implements PhoneVerificationProviderInterface
{
    private Client $client;

    private string $verifyServiceSid;

    public function __construct()
    {
        $accountSid = config('phone.providers.twilio_verify.account_sid');
        $authToken = config('phone.providers.twilio_verify.auth_token');
        $this->verifyServiceSid = config('phone.providers.twilio_verify.verify_service_sid');

        $this->client = new Client($accountSid, $authToken);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sendVerification(string $e164, string $channel, string $purpose, array $metadata = []): array
    {
        $this->ensureChannelSupported($channel);

        try {
            $verification = $this->client
                ->verify
                ->v2
                ->services($this->verifyServiceSid)
                ->verifications
                ->create($e164, $channel);
        } catch (TwilioException $e) {
            throw VerificationException::providerError($e->getMessage());
        }

        return [
            'provider_reference' => $verification->sid,
            'status' => $verification->status,
            'channel' => $verification->channel,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function checkVerification(string $e164, string $code, string $purpose, array $metadata = []): array
    {
        try {
            $check = $this->client
                ->verify
                ->v2
                ->services($this->verifyServiceSid)
                ->verificationChecks
                ->create(['to' => $e164, 'code' => $code]);
        } catch (TwilioException $e) {
            throw VerificationException::providerError($e->getMessage());
        }

        return [
            'valid' => $check->status === 'approved',
            'status' => $check->status,
        ];
    }

    private function ensureChannelSupported(string $channel): void
    {
        $supported = config('phone.supported_channels', ['sms']);

        if (! in_array($channel, $supported, strict: true)) {
            throw VerificationException::unsupportedChannel($channel);
        }
    }
}
