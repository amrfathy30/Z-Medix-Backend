<?php

namespace App\Services\Auth;

use App\Enums\AccountStatus;
use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Exceptions\Auth\EmailOtpException;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use Closure;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Issues and verifies email one-time passcodes.
 *
 * Only a hash of each code is persisted. Issuing a new code expires every other
 * pending code for the same user and purpose, so at most one code per purpose is
 * ever live. Delivery limits are scoped per purpose, so email verification and
 * password reset never share a cooldown or hourly allowance.
 *
 * @phpstan-type OtpMetadata array{ip_address?: string|null, user_agent?: string|null}
 */
class EmailOtpService
{
    /**
     * Issue the first code for a freshly registered account.
     *
     * Delivery limits are recorded but not enforced here: the account has just
     * been created, so no earlier delivery for this address can exist, and the
     * student must always receive the code that activates their account.
     *
     * @param  OtpMetadata  $metadata
     */
    public function sendForRegistration(User $user, array $metadata = []): EmailVerificationOtp
    {
        return $this->issue($user, OtpPurpose::EmailVerification, $metadata);
    }

    /**
     * Issue a password reset code for the given email address.
     *
     * Delivery limits are checked before the account is looked up, and are
     * consumed whether or not the address resolves to an eligible account, so the
     * response cannot be used to probe for registered email addresses.
     *
     * @param  OtpMetadata  $metadata
     *
     * @throws EmailOtpException
     */
    public function sendForPasswordReset(string $email, array $metadata = []): void
    {
        $this->deliverTo($email, OtpPurpose::PasswordReset, $metadata);
    }

    /**
     * Re-issue a code for the given email address, enumeration-safely.
     *
     * @param  OtpMetadata  $metadata
     *
     * @throws EmailOtpException
     */
    public function resend(
        string $email,
        OtpPurpose $purpose = OtpPurpose::EmailVerification,
        array $metadata = [],
    ): void {
        $this->deliverTo($email, $purpose, $metadata);
    }

    /**
     * Verify a submitted code and consume the matching OTP.
     *
     * Deliberately does not touch the user: no email verification timestamp, no
     * status change, no Verified event. Callers that need those apply them through
     * $onVerified, which runs in the same transaction that marks the OTP verified.
     *
     * @param  Closure(User, EmailVerificationOtp): void|null  $onVerified
     *
     * @throws EmailOtpException
     */
    public function verify(
        User $user,
        string $code,
        OtpPurpose $purpose = OtpPurpose::EmailVerification,
        ?Closure $onVerified = null,
    ): EmailVerificationOtp {
        $otp = $this->resolvePendingOtp($user, $purpose);

        // Outside the transaction below, so a failed attempt is still recorded.
        $this->assertCodeMatches($otp, $code);

        DB::transaction(function () use ($otp, $user, $onVerified): void {
            $otp->update([
                'status' => OtpStatus::Verified,
                'verified_at' => now(),
            ]);

            if ($onVerified !== null) {
                $onVerified($user, $otp);
            }
        });

        return $otp->refresh();
    }

    /**
     * Verify an email-verification code and activate the account.
     *
     * The OTP state, the email verification timestamp and the account status all
     * change in one commit.
     *
     * @throws EmailOtpException
     */
    public function verifyAndActivate(User $user, string $code): EmailVerificationOtp
    {
        $otp = $this->verify(
            $user,
            $code,
            OtpPurpose::EmailVerification,
            onVerified: function (User $user): void {
                $user->forceFill([
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'status' => AccountStatus::Active,
                ])->save();
            },
        );

        event(new Verified($user));

        return $otp;
    }

    /**
     * Run the delivery limits, then issue a code if the address resolves to an
     * account eligible for this purpose. The limits are consumed either way.
     *
     * @param  OtpMetadata  $metadata
     *
     * @throws EmailOtpException
     */
    private function deliverTo(string $email, OtpPurpose $purpose, array $metadata): void
    {
        $ipAddress = $metadata['ip_address'] ?? null;

        $this->assertDeliveryAllowed($email, $purpose, $ipAddress);

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! $this->isEligibleFor($user, $purpose)) {
            $this->recordDelivery($email, $purpose, $ipAddress);

            return;
        }

        $this->issue($user, $purpose, $metadata);
    }

    /**
     * Whether this account may receive a code for the given purpose.
     */
    private function isEligibleFor(User $user, OtpPurpose $purpose): bool
    {
        return match ($purpose) {
            // Verification codes are pointless once the address is confirmed.
            OtpPurpose::EmailVerification => ! $user->hasVerifiedEmail(),
            // Resetting a password is only meaningful for a usable account, and must
            // never become a back door into activating or unblocking one.
            OtpPurpose::PasswordReset => $user->status === AccountStatus::Active,
            default => false,
        };
    }

    /**
     * @param  OtpMetadata  $metadata
     */
    private function issue(User $user, OtpPurpose $purpose, array $metadata): EmailVerificationOtp
    {
        $code = $this->generateCode();

        $otp = DB::transaction(function () use ($user, $purpose, $metadata, $code): EmailVerificationOtp {
            $user->emailVerificationOtps()
                ->where('purpose', $purpose)
                ->where('status', OtpStatus::Pending)
                ->update(['status' => OtpStatus::Expired]);

            return $user->emailVerificationOtps()->create([
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'status' => OtpStatus::Pending,
                'attempts' => 0,
                'max_attempts' => $this->maxAttempts(),
                'expires_at' => now()->addMinutes($this->expiryMinutes()),
                'ip_address' => $metadata['ip_address'] ?? null,
                'user_agent' => $metadata['user_agent'] ?? null,
            ]);
        });

        $this->recordDelivery($user->email, $purpose, $metadata['ip_address'] ?? null);

        $user->notify(new EmailOtpNotification($code, $otp->expires_at, $purpose));

        return $otp;
    }

    /** @throws EmailOtpException */
    private function resolvePendingOtp(User $user, OtpPurpose $purpose): EmailVerificationOtp
    {
        $otp = $user->emailVerificationOtps()
            ->where('purpose', $purpose)
            ->pending()
            ->latest('id')
            ->first();

        if ($otp === null) {
            throw EmailOtpException::noPendingOtp();
        }

        if ($otp->isExpired()) {
            $otp->update(['status' => OtpStatus::Expired]);

            throw EmailOtpException::expired();
        }

        if ($otp->hasExhaustedAttempts()) {
            $otp->update(['status' => OtpStatus::Failed]);

            throw EmailOtpException::tooManyAttempts();
        }

        return $otp;
    }

    /** @throws EmailOtpException */
    private function assertCodeMatches(EmailVerificationOtp $otp, string $code): void
    {
        $otp->increment('attempts');
        $otp->refresh();

        if ($this->matchesTestCode($code) || Hash::check($code, $otp->code_hash)) {
            return;
        }

        if ($otp->hasExhaustedAttempts()) {
            $otp->update(['status' => OtpStatus::Failed]);

            throw EmailOtpException::tooManyAttempts();
        }

        throw EmailOtpException::invalidCode();
    }

    /**
     * Whether the submitted code is the configured non-production test code.
     *
     * Fails closed: the code and the environment allow-list must both be present
     * and well formed, the current environment must be listed, and production is
     * refused even when it appears in the list.
     *
     * This replaces the code comparison only — the caller has already established
     * that a real pending, unexpired OTP with attempts remaining exists.
     */
    private function matchesTestCode(string $code): bool
    {
        $testCode = config('auth_features.email_otp.test_code');
        $environments = config('auth_features.email_otp.test_code_environments');

        if (! is_string($testCode) || $testCode === '') {
            return false;
        }

        if (! is_array($environments) || $environments === []) {
            return false;
        }

        if (in_array('production', $environments, strict: true)) {
            return false;
        }

        if (! app()->environment($environments)) {
            return false;
        }

        return hash_equals($testCode, $code);
    }

    /** @throws EmailOtpException */
    private function assertDeliveryAllowed(string $email, OtpPurpose $purpose, ?string $ipAddress): void
    {
        $cooldownKey = $this->cooldownKey($email, $purpose, $ipAddress);

        if (RateLimiter::tooManyAttempts($cooldownKey, maxAttempts: 1)) {
            throw EmailOtpException::cooldownActive(RateLimiter::availableIn($cooldownKey));
        }

        $quotaKey = $this->quotaKey($email, $purpose, $ipAddress);

        if (RateLimiter::tooManyAttempts($quotaKey, $this->maxSendsPerHour())) {
            throw EmailOtpException::sendLimitReached(RateLimiter::availableIn($quotaKey));
        }
    }

    private function recordDelivery(string $email, OtpPurpose $purpose, ?string $ipAddress): void
    {
        RateLimiter::increment(
            $this->cooldownKey($email, $purpose, $ipAddress),
            $this->resendCooldownSeconds(),
        );

        RateLimiter::increment(
            $this->quotaKey($email, $purpose, $ipAddress),
            decaySeconds: 3600,
        );
    }

    private function cooldownKey(string $email, OtpPurpose $purpose, ?string $ipAddress): string
    {
        return 'email-otp-cooldown:'.$this->throttleScope($email, $purpose, $ipAddress);
    }

    private function quotaKey(string $email, OtpPurpose $purpose, ?string $ipAddress): string
    {
        return 'email-otp-quota:'.$this->throttleScope($email, $purpose, $ipAddress);
    }

    private function throttleScope(string $email, OtpPurpose $purpose, ?string $ipAddress): string
    {
        return sha1(mb_strtolower($email).'|'.$purpose->value.'|'.($ipAddress ?? 'unknown'));
    }

    private function generateCode(): string
    {
        $length = max(4, $this->codeLength());

        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT,
        );
    }

    private function codeLength(): int
    {
        return (int) config('auth_features.email_otp.length', 6);
    }

    private function expiryMinutes(): int
    {
        return (int) config('auth_features.email_otp.expiry_minutes', 10);
    }

    private function maxAttempts(): int
    {
        return (int) config('auth_features.email_otp.max_attempts', 5);
    }

    private function resendCooldownSeconds(): int
    {
        return (int) config('auth_features.email_otp.resend_cooldown_seconds', 60);
    }

    private function maxSendsPerHour(): int
    {
        return (int) config('auth_features.email_otp.max_sends_per_hour', 5);
    }
}
