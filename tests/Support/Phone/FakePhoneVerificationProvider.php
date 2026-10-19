<?php

namespace Tests\Support\Phone;

use App\Contracts\PhoneVerificationProviderInterface;
use App\Exceptions\Phone\VerificationException;

/**
 * In-memory stand-in for Twilio Verify. No SMS is ever sent.
 *
 * Accepts $validCode and records every call so tests can assert on what the
 * provider was asked to do, including the metadata threaded through from the
 * HTTP request.
 */
class FakePhoneVerificationProvider implements PhoneVerificationProviderInterface
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @var list<array<string, mixed>> */
    public array $checked = [];

    public bool $shouldFailDelivery = false;

    public function __construct(public string $validCode = '123456') {}

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sendVerification(string $e164, string $channel, string $purpose, array $metadata = []): array
    {
        if ($this->shouldFailDelivery) {
            // Mirrors TwilioVerifyProvider, which masks the upstream text before
            // it leaves the provider.
            throw VerificationException::deliveryFailed();
        }

        $this->sent[] = compact('e164', 'channel', 'purpose', 'metadata');

        return [
            'provider_reference' => 'fake-sid-'.count($this->sent),
            'status' => 'pending',
            'channel' => $channel,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function checkVerification(string $e164, string $code, string $purpose, array $metadata = []): array
    {
        $this->checked[] = compact('e164', 'code', 'purpose', 'metadata');

        $valid = $code === $this->validCode;

        return [
            'valid' => $valid,
            'status' => $valid ? 'approved' : 'pending',
        ];
    }

    public function sendCount(): int
    {
        return count($this->sent);
    }

    /** @return list<string> */
    public function numbersSentTo(): array
    {
        return array_map(static fn (array $call): string => $call['e164'], $this->sent);
    }
}
