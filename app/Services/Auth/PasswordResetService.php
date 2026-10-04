<?php

namespace App\Services\Auth;

use App\Enums\AccountStatus;
use App\Enums\OtpPurpose;
use App\Exceptions\Auth\EmailOtpException;
use App\Exceptions\Auth\PasswordResetException;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Owns the reset-token half of the student password reset flow: issuing a
 * short-lived single-use token once the reset OTP is verified, then consuming it
 * to change the password.
 *
 * @phpstan-type ResetMetadata array{ip_address?: string|null, user_agent?: string|null}
 */
class PasswordResetService
{
    public function __construct(
        private readonly EmailOtpService $emailOtp,
    ) {}

    /**
     * Verify a password reset OTP and hand back a plaintext reset token.
     *
     * The OTP is consumed and the token is created in one commit. Nothing about
     * the user's email verification state or account status is touched, so this
     * path can never activate or unblock an account.
     *
     * @param  ResetMetadata  $metadata
     *
     * @throws EmailOtpException|PasswordResetException
     */
    public function verifyOtpAndIssueToken(User $user, string $code, array $metadata = []): string
    {
        $this->assertEligible($user);

        $plainToken = $this->generateToken();

        $this->emailOtp->verify(
            $user,
            $code,
            OtpPurpose::PasswordReset,
            onVerified: function (User $user) use ($plainToken, $metadata): void {
                // At most one live reset request per user.
                $user->passwordResetRequests()
                    ->unused()
                    ->update(['used_at' => now()]);

                $user->passwordResetRequests()->create([
                    'token_hash' => $this->hashToken($plainToken),
                    'expires_at' => now()->addMinutes($this->tokenTtlMinutes()),
                    'ip_address' => $metadata['ip_address'] ?? null,
                    'user_agent' => $metadata['user_agent'] ?? null,
                ]);
            },
        );

        return $plainToken;
    }

    /**
     * Consume a reset token and set the new password.
     *
     * Marking the token used, writing the password and revoking the account's API
     * tokens all happen in one commit, so a replayed token cannot reset twice.
     *
     * @throws PasswordResetException
     */
    public function resetPassword(string $plainToken, string $password): User
    {
        $request = PasswordResetRequest::query()
            ->where('token_hash', $this->hashToken($plainToken))
            ->first();

        if ($request === null) {
            throw PasswordResetException::invalidToken();
        }

        if ($request->isUsed()) {
            throw PasswordResetException::usedToken();
        }

        if ($request->isExpired()) {
            throw PasswordResetException::expiredToken();
        }

        $user = $request->user;

        if ($user === null) {
            throw PasswordResetException::invalidToken();
        }

        $this->assertEligible($user);

        return DB::transaction(function () use ($request, $user, $password): User {
            $request->update(['used_at' => now()]);

            $user->forceFill(['password' => Hash::make($password)])->save();

            $user->tokens()->delete();

            return $user;
        });
    }

    /**
     * Password reset is only available to active accounts, and must never be a
     * route to changing an account's status.
     *
     * @throws PasswordResetException
     */
    private function assertEligible(User $user): void
    {
        if (
            $user->status === AccountStatus::Suspended
            || $user->status === AccountStatus::Blocked
            || $user->status === AccountStatus::Inactive
        ) {
            throw PasswordResetException::accountSuspended();
        }

        if ($user->status !== AccountStatus::Active) {
            throw PasswordResetException::accountNotActive();
        }
    }

    private function generateToken(): string
    {
        return Str::random(64);
    }

    private function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function tokenTtlMinutes(): int
    {
        return (int) config('auth_features.password_reset.token_ttl_minutes', 15);
    }
}
