<?php

namespace App\Services\Phone;

use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Enums\PhoneStatus;
use App\Exceptions\Phone\VerificationException;
use App\Models\PhoneNumber;
use App\Models\PhoneVerificationOtp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\Country;

class ChangePhoneService
{
    public function __construct(private readonly PhoneNumberService $phoneNumbers) {}

    /** @param array{ip_address?: string|null, user_agent?: string|null} $metadata */
    public function send(User $user, ?string $phone, ?string $channel, array $metadata): PhoneVerificationOtp
    {
        $this->phoneNumbers->ensureOtpEnabled();

        return DB::transaction(function () use ($user, $phone, $channel, $metadata): PhoneVerificationOtp {
            $current = $this->lockedPhone($user);

            if ($phone === null) {
                $pending = $this->pendingOtp($current);
                $phone = $pending->target_phone;
            }

            $country = Country::query()->whereKey($user->country_id)->value('iso2')
                ?: config('phone.default_country');
            $target = $this->phoneNumbers->validateReplacement($current, $phone, $country);
            $recipient = $this->recipient($current, $target);
            $otp = $this->phoneNumbers->requestVerification(
                $recipient,
                OtpPurpose::PhoneChange->value,
                $channel,
                $metadata,
            );
            $otp->update(['target_phone' => $target]);

            return $otp;
        });
    }

    /** @param array{ip_address?: string|null, user_agent?: string|null} $metadata */
    public function verify(User $user, string $code, array $metadata): PhoneNumber
    {
        $this->phoneNumbers->ensureOtpEnabled();

        $result = DB::transaction(function () use ($user, $code, $metadata): PhoneNumber|VerificationException {
            $current = $this->lockedPhone($user);

            try {
                $pending = $this->pendingOtp($current);
                $target = $this->phoneNumbers->validateReplacement($current, $pending->target_phone, $current->country_iso2);
                $verified = $this->phoneNumbers->verifyPhone(
                    $this->recipient($current, $target),
                    $code,
                    OtpPurpose::PhoneChange->value,
                    $metadata,
                    markPhoneVerified: false,
                );

                if (! $verified) {
                    return VerificationException::invalidCode();
                }
            } catch (VerificationException $e) {
                // Commit failed attempts and expiry transitions while keeping the current phone intact.
                return $e;
            }

            $updated = $this->phoneNumbers->updatePhone(
                $current,
                ['phone' => $target],
                allowedCountries: config('auth_features.registration.allowed_phone_countries', []),
            );
            $updated->update(['status' => PhoneStatus::Verified, 'verified_at' => now()]);
            $pending->update(['target_phone' => null]);

            return $updated->fresh();
        });

        if ($result instanceof VerificationException) {
            throw $result;
        }

        return $result;
    }

    private function lockedPhone(User $user): PhoneNumber
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

        return $user->phoneNumbers()->orderByDesc('is_primary')->orderBy('id')->lockForUpdate()->first()
            ?? throw VerificationException::noPhoneOnRecord();
    }

    private function pendingOtp(PhoneNumber $phone): PhoneVerificationOtp
    {
        $otp = $phone->otps()->where('purpose', OtpPurpose::PhoneChange)
            ->latest('id')->lockForUpdate()->first();

        if ($otp === null || $otp->target_phone === null || $otp->status === OtpStatus::Verified) {
            throw VerificationException::noPendingOtp();
        }

        if ($otp->status === OtpStatus::Failed) {
            throw VerificationException::tooManyAttempts();
        }

        if ($otp->status !== OtpStatus::Pending || $otp->expires_at->isPast()) {
            throw VerificationException::expired();
        }

        return $otp;
    }

    private function recipient(PhoneNumber $current, string $target): PhoneNumber
    {
        $recipient = new PhoneNumber;
        $recipient->setRawAttributes([...$current->getAttributes(), 'e164_number' => $target], sync: true);
        $recipient->exists = true;

        return $recipient;
    }
}
