<?php

namespace App\Services\Phone;

use App\Contracts\PhoneVerificationProviderInterface;
use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Enums\PhoneStatus;
use App\Exceptions\Phone\InvalidPhoneException;
use App\Exceptions\Phone\MaxPhoneNumbersReachedException;
use App\Exceptions\Phone\PhoneAlreadyExistsException;
use App\Exceptions\Phone\VerificationException;
use App\Models\PhoneNumber;
use App\Models\PhoneVerificationOtp;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Propaganistas\LaravelPhone\PhoneNumber as PhoneParser;

/**
 * @phpstan-type OtpMetadata array{ip_address?: string|null, user_agent?: string|null}
 */
class PhoneNumberService
{
    public function __construct(
        private readonly PhoneVerificationProviderInterface $provider
    ) {}

    /**
     * Add a new phone number for any Eloquent model owner.
     *
     * @param  array{phone: string, country?: string}  $data
     * @param  list<string>|null  $allowedCountries  Overrides config('phone.allowed_countries')
     *                                               for this call. An empty array accepts every
     *                                               parseable country; null uses the config value.
     */
    public function addPhone(Model $owner, array $data, ?array $allowedCountries = null): PhoneNumber
    {
        [$e164, $countryIso2, $countryCode, $nationalNumber] = $this->parseAndValidate(
            $data['phone'],
            $data['country'] ?? config('phone.default_country'),
            $allowedCountries
        );

        $this->enforceUniquenessForOwner($owner, $e164);
        $this->enforceGlobalUniqueness($e164);
        $this->enforceMaxPhones($owner);

        $isFirst = ! $owner->phoneNumbers()->withTrashed()->exists();

        try {
            return DB::transaction(function () use ($owner, $e164, $countryIso2, $countryCode, $nationalNumber, $isFirst) {
                return $owner->phoneNumbers()->create([
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->getKey(),
                    'country_iso2' => $countryIso2,
                    'country_code' => $countryCode,
                    'national_number' => $nationalNumber,
                    'e164_number' => $e164,
                    'is_primary' => $isFirst,
                    'status' => PhoneStatus::Pending,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw PhoneAlreadyExistsException::globally($e164);
        }
    }

    /**
     * Update the phone number. Resets verification if the E.164 number changes.
     *
     * @param  array{phone: string, country?: string}  $data
     * @param  list<string>|null  $allowedCountries  Overrides config('phone.allowed_countries')
     *                                               for this call. An empty array accepts every
     *                                               parseable country; null uses the config value.
     */
    public function updatePhone(PhoneNumber $phoneNumber, array $data, ?array $allowedCountries = null): PhoneNumber
    {
        [$e164, $countryIso2, $countryCode, $nationalNumber] = $this->parseAndValidate(
            $data['phone'],
            $data['country'] ?? $phoneNumber->country_iso2 ?? config('phone.default_country'),
            $allowedCountries
        );

        $numberChanged = $e164 !== $phoneNumber->e164_number;

        if ($numberChanged) {
            $owner = $phoneNumber->owner;
            $this->enforceUniquenessForOwner($owner, $e164, excludeId: $phoneNumber->id);
            $this->enforceGlobalUniqueness($e164, excludeId: $phoneNumber->id);
        }

        try {
            return DB::transaction(function () use ($phoneNumber, $e164, $countryIso2, $countryCode, $nationalNumber, $numberChanged) {
                $phoneNumber->update([
                    'country_iso2' => $countryIso2,
                    'country_code' => $countryCode,
                    'national_number' => $nationalNumber,
                    'e164_number' => $e164,
                    ...($numberChanged ? [
                        'status' => PhoneStatus::Pending,
                        'verified_at' => null,
                    ] : []),
                ]);

                if ($numberChanged) {
                    $phoneNumber->otps()->where('status', OtpStatus::Pending)
                        ->update(['status' => OtpStatus::Expired]);
                }

                return $phoneNumber->fresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw PhoneAlreadyExistsException::globally($e164);
        }
    }

    /**
     * Soft-delete a phone number. If it was primary, promote another phone.
     */
    public function deletePhone(PhoneNumber $phoneNumber): bool
    {
        return DB::transaction(function () use ($phoneNumber) {
            $wasPrimary = $phoneNumber->is_primary;
            $owner = $phoneNumber->owner;

            $phoneNumber->delete();

            if ($wasPrimary && $owner !== null) {
                $next = $owner->phoneNumbers()->first();

                if ($next !== null) {
                    $next->update(['is_primary' => true]);
                }
            }

            return true;
        });
    }

    /**
     * Return all active (non-deleted) phone numbers for the given owner.
     *
     * @return Collection<int, PhoneNumber>
     */
    public function listPhones(Model $owner): Collection
    {
        return $owner->phoneNumbers()->get();
    }

    /**
     * Mark a phone number as primary and unset all other phones for the same owner.
     */
    public function setPrimary(PhoneNumber $phoneNumber): PhoneNumber
    {
        return DB::transaction(function () use ($phoneNumber) {
            $phoneNumber->owner->phoneNumbers()
                ->where('id', '!=', $phoneNumber->id)
                ->update(['is_primary' => false]);

            $phoneNumber->update(['is_primary' => true]);

            return $phoneNumber->fresh();
        });
    }

    /**
     * Issue an OTP on behalf of a client request, enforcing the resend cooldown
     * and the hourly send allowance before anything billable happens.
     *
     * This is the entry point for the public API. sendVerification() below stays
     * the unthrottled primitive for internal and administrative flows.
     *
     * @param  OtpMetadata  $metadata
     *
     * @throws VerificationException
     */
    public function requestVerification(
        PhoneNumber $phoneNumber,
        string $purpose = 'phone_verification',
        ?string $channel = null,
        array $metadata = [],
    ): PhoneVerificationOtp {
        $purposeEnum = OtpPurpose::from($purpose);
        $ipAddress = $metadata['ip_address'] ?? null;

        $this->assertDeliveryAllowed($phoneNumber->e164_number, $purposeEnum, $ipAddress);

        $otp = $this->sendVerification($phoneNumber, $purpose, $channel, $metadata);

        // Recorded only after a delivery actually succeeded, so a provider outage
        // does not consume the student's hourly allowance.
        $this->recordDelivery($phoneNumber->e164_number, $purposeEnum, $ipAddress);

        return $otp;
    }

    /**
     * Initiate OTP verification for a phone number.
     *
     * The provider reference (e.g. Twilio Verify SID) is stored in code_hash
     * since the external provider owns the OTP code — we do not generate it locally.
     *
     * @param  OtpMetadata  $metadata
     *
     * @throws VerificationException
     */
    public function sendVerification(
        PhoneNumber $phoneNumber,
        string $purpose = 'phone_verification',
        ?string $channel = null,
        array $metadata = [],
    ): PhoneVerificationOtp {
        $channel ??= config('phone.default_channel', 'sms');

        $supported = config('phone.supported_channels', ['sms']);
        if (! in_array($channel, $supported, strict: true)) {
            throw VerificationException::unsupportedChannel($channel);
        }

        $purposeEnum = OtpPurpose::from($purpose);

        return DB::transaction(function () use ($phoneNumber, $purposeEnum, $channel, $metadata) {
            $phoneNumber->otps()
                ->where('purpose', $purposeEnum)
                ->where('status', OtpStatus::Pending)
                ->update(['status' => OtpStatus::Expired]);

            $result = $this->provider->sendVerification(
                $phoneNumber->e164_number,
                $channel,
                $purposeEnum->value,
                $metadata
            );

            return $phoneNumber->otps()->create([
                'purpose' => $purposeEnum,
                'code_hash' => $result['provider_reference'],
                'status' => OtpStatus::Pending,
                'attempts' => 0,
                'max_attempts' => $this->maxAttempts(),
                'expires_at' => now()->addMinutes(config('phone.otp_expiry_minutes', 10)),
                'ip_address' => $metadata['ip_address'] ?? null,
                'user_agent' => $metadata['user_agent'] ?? null,
            ]);
        });
    }

    /**
     * Check a user-submitted OTP code via the provider.
     * Marks the phone as verified on success.
     *
     * @param  OtpMetadata  $metadata
     *
     * @throws VerificationException
     */
    public function verifyPhone(
        PhoneNumber $phoneNumber,
        string $code,
        string $purpose = 'phone_verification',
        array $metadata = [],
    ): bool {
        $purposeEnum = OtpPurpose::from($purpose);

        $otp = $phoneNumber->otps()
            ->where('purpose', $purposeEnum)
            ->where('status', OtpStatus::Pending)
            ->latest()
            ->first();

        if ($otp === null) {
            // A code burned by exhausting its attempts is reported as such, so a
            // client can tell "you ran out of tries" from "you never asked for a
            // code" — both of which otherwise leave no pending row behind.
            $burned = $phoneNumber->otps()
                ->where('purpose', $purposeEnum)
                ->where('status', OtpStatus::Failed)
                ->latest()
                ->first();

            if ($burned !== null && $burned->attempts >= $burned->max_attempts) {
                throw VerificationException::tooManyAttempts();
            }

            throw VerificationException::noPendingOtp();
        }

        if ($otp->expires_at->isPast()) {
            $otp->update(['status' => OtpStatus::Expired]);
            throw VerificationException::expired();
        }

        if ($otp->attempts >= $otp->max_attempts) {
            $otp->update(['status' => OtpStatus::Failed]);
            throw VerificationException::tooManyAttempts();
        }

        $otp->increment('attempts');

        // The test code short-circuits the provider entirely, so no Twilio Verify
        // request is made and no SMS is billed. It is refused outright in
        // production and whenever the allow-list is not explicitly configured.
        $result = $this->matchesTestCode($code)
            ? ['valid' => true, 'status' => 'approved']
            : $this->provider->checkVerification(
                $phoneNumber->e164_number,
                $code,
                $purposeEnum->value,
                $metadata
            );

        if ($result['valid']) {
            DB::transaction(function () use ($otp, $phoneNumber) {
                $otp->update([
                    'status' => OtpStatus::Verified,
                    'verified_at' => now(),
                ]);

                $phoneNumber->update([
                    'status' => PhoneStatus::Verified,
                    'verified_at' => now(),
                ]);
            });

            return true;
        }

        if ($otp->attempts >= $otp->max_attempts) {
            $otp->update(['status' => OtpStatus::Failed]);
        }

        return false;
    }

    /**
     * Parse and validate a phone number. Returns [e164, countryIso2, countryCode, nationalNumber].
     *
     * @param  list<string>|null  $allowedCountries
     * @return array{string, string, string, string}
     */
    private function parseAndValidate(string $phone, string $defaultCountry, ?array $allowedCountries = null): array
    {
        $allowedCountries ??= config('phone.allowed_countries', []);

        try {
            $parsed = new PhoneParser($phone, $defaultCountry);

            if (! $parsed->isValid()) {
                throw InvalidPhoneException::invalidNumber($phone);
            }
        } catch (InvalidPhoneException $e) {
            throw $e;
        } catch (\Throwable) {
            throw InvalidPhoneException::invalidNumber($phone);
        }

        $countryIso2 = $parsed->getCountry();

        if (! empty($allowedCountries) && ! in_array($countryIso2, $allowedCountries, strict: true)) {
            throw InvalidPhoneException::unsupportedCountry($countryIso2);
        }

        $e164 = $parsed->formatE164();
        $libPhone = $parsed->toLibPhoneObject();
        $countryCode = '+'.$libPhone->getCountryCode();
        $nationalNumber = (string) $libPhone->getNationalNumber();

        return [$e164, $countryIso2, $countryCode, $nationalNumber];
    }

    private function enforceUniquenessForOwner(Model $owner, string $e164, ?int $excludeId = null): void
    {
        $query = $owner->phoneNumbers()
            ->where('e164_number', $e164);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw PhoneAlreadyExistsException::forOwner($e164);
        }
    }

    private function enforceGlobalUniqueness(string $e164, ?int $excludeId = null): void
    {
        if (! config('phone.unique_globally', true)) {
            return;
        }

        $query = PhoneNumber::where('e164_number', $e164);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw PhoneAlreadyExistsException::globally($e164);
        }
    }

    private function enforceMaxPhones(Model $owner): void
    {
        $max = config('phone.max_numbers_per_owner', 3);

        if ($owner->phoneNumbers()->count() >= $max) {
            throw MaxPhoneNumbersReachedException::forOwner($max);
        }
    }

    /**
     * Whether the submitted code is the configured non-production test code.
     *
     * Fails closed, matching EmailOtpService::matchesTestCode(): the code and the
     * environment allow-list must both be present and well formed, the current
     * environment must be listed, and production is refused even when it appears
     * in the list. An unset allow-list — the shipped default — disables it.
     *
     * This replaces the provider check only. The caller has already established
     * that a real pending, unexpired OTP with attempts remaining exists, and the
     * attempt counter has already moved.
     */
    private function matchesTestCode(string $code): bool
    {
        $testCode = config('phone.otp_test_code');
        $environments = config('phone.otp_test_code_environments');

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

    /**
     * Every delivery is billable, so both limits are scoped to the number, the
     * purpose and the client IP — the same scoping EmailOtpService applies.
     *
     * @throws VerificationException
     */
    private function assertDeliveryAllowed(string $e164, OtpPurpose $purpose, ?string $ipAddress): void
    {
        $cooldownKey = $this->cooldownKey($e164, $purpose, $ipAddress);

        if (RateLimiter::tooManyAttempts($cooldownKey, maxAttempts: 1)) {
            throw VerificationException::cooldownActive(RateLimiter::availableIn($cooldownKey));
        }

        $quotaKey = $this->quotaKey($e164, $purpose, $ipAddress);

        if (RateLimiter::tooManyAttempts($quotaKey, $this->maxSendsPerHour())) {
            throw VerificationException::sendLimitReached(RateLimiter::availableIn($quotaKey));
        }
    }

    private function recordDelivery(string $e164, OtpPurpose $purpose, ?string $ipAddress): void
    {
        RateLimiter::increment(
            $this->cooldownKey($e164, $purpose, $ipAddress),
            $this->resendCooldownSeconds(),
        );

        RateLimiter::increment(
            $this->quotaKey($e164, $purpose, $ipAddress),
            decaySeconds: 3600,
        );
    }

    private function cooldownKey(string $e164, OtpPurpose $purpose, ?string $ipAddress): string
    {
        return 'phone-otp-cooldown:'.$this->throttleScope($e164, $purpose, $ipAddress);
    }

    private function quotaKey(string $e164, OtpPurpose $purpose, ?string $ipAddress): string
    {
        return 'phone-otp-quota:'.$this->throttleScope($e164, $purpose, $ipAddress);
    }

    private function throttleScope(string $e164, OtpPurpose $purpose, ?string $ipAddress): string
    {
        return sha1($e164.'|'.$purpose->value.'|'.($ipAddress ?? 'unknown'));
    }

    private function maxAttempts(): int
    {
        return (int) config('phone.otp_max_attempts', 5);
    }

    private function resendCooldownSeconds(): int
    {
        return (int) config('phone.otp_resend_cooldown_seconds', 60);
    }

    private function maxSendsPerHour(): int
    {
        return (int) config('phone.otp_max_sends_per_hour', 5);
    }
}
