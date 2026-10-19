<?php

namespace App\Services\Phone\Providers;

use App\Contracts\PhoneVerificationProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Local-development stand-in for Twilio Verify. Writes the delivery to the log
 * instead of sending an SMS, so the send endpoint works end to end on a machine
 * with no Twilio credentials.
 *
 * It deliberately approves nothing: checkVerification() always reports the code
 * as invalid. The only way to complete verification against this provider is the
 * `phone.otp_test_code`, which carries its own production guard. Keeping the
 * single bypass behind that one guard means selecting this provider by mistake
 * cannot, on its own, let an arbitrary code through.
 *
 * Select it with PHONE_VERIFICATION_PROVIDER=log.
 */
class LogPhoneVerificationProvider implements PhoneVerificationProviderInterface
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sendVerification(string $e164, string $channel, string $purpose, array $metadata = []): array
    {
        Log::info('Phone verification code requested (log provider — no SMS sent).', [
            'to' => $e164,
            'channel' => $channel,
            'purpose' => $purpose,
            'hint' => 'Submit the configured phone.otp_test_code to complete verification.',
        ]);

        return [
            'provider_reference' => 'log-'.bin2hex(random_bytes(8)),
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
        Log::info('Phone verification check received (log provider — always rejected).', [
            'to' => $e164,
            'purpose' => $purpose,
        ]);

        return [
            'valid' => false,
            'status' => 'pending',
        ];
    }
}
