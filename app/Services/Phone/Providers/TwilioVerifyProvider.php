<?php

namespace App\Services\Phone\Providers;

use App\Contracts\PhoneVerificationProviderInterface;
use App\Exceptions\Phone\VerificationException;
use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

class TwilioVerifyProvider implements PhoneVerificationProviderInterface
{
    private ?Client $client = null;

    /**
     * The Twilio client is built on first use rather than on construction so
     * that resolving this provider does not require Twilio credentials. Flows
     * that touch phone numbers without sending an OTP — student registration,
     * for example — never reach the client.
     */
    private function client(): Client
    {
        return $this->client ??= new Client(
            config('phone.providers.twilio_verify.account_sid'),
            config('phone.providers.twilio_verify.auth_token'),
        );
    }

    private function verifyServiceSid(): string
    {
        $sid = config('phone.providers.twilio_verify.verify_service_sid');

        if (! is_string($sid) || $sid === '') {
            // A deployment mistake, not something a client can act on, so it is
            // logged and answered with the same generic delivery failure.
            Log::error('Twilio Verify service SID is not configured.');

            throw VerificationException::deliveryFailed();
        }

        return $sid;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sendVerification(string $e164, string $channel, string $purpose, array $metadata = []): array
    {
        $this->ensureChannelSupported($channel);

        try {
            $verification = $this->client()
                ->verify
                ->v2
                ->services($this->verifyServiceSid())
                ->verifications
                ->create($e164, $channel);
        } catch (TwilioException $e) {
            throw $this->maskProviderFailure('send', $e);
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
            $check = $this->client()
                ->verify
                ->v2
                ->services($this->verifyServiceSid())
                ->verificationChecks
                ->create(['to' => $e164, 'code' => $code]);
        } catch (TwilioException $e) {
            throw $this->maskProviderFailure('check', $e);
        }

        return [
            'valid' => $check->status === 'approved',
            'status' => $check->status,
        ];
    }

    /**
     * Twilio's own wording can name the account, the Verify service or the
     * recipient, so only the operation and numeric error code are logged.
     * API clients receive a generic message.
     */
    private function maskProviderFailure(string $operation, TwilioException $e): VerificationException
    {
        Log::warning('Twilio Verify request failed.', [
            'operation' => $operation,
            'code' => $e->getCode(),
        ]);

        return VerificationException::deliveryFailed();
    }

    private function ensureChannelSupported(string $channel): void
    {
        $supported = config('phone.supported_channels', ['sms']);

        if (! in_array($channel, $supported, strict: true)) {
            throw VerificationException::unsupportedChannel($channel);
        }
    }
}
