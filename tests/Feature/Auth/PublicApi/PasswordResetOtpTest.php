<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Models\EmailVerificationOtp;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use RefreshDatabase;

    private string $forgotUrl = '/api/public/auth/forgot-password';

    private string $resendUrl = '/api/public/auth/forgot-password/resend';

    private string $verifyUrl = '/api/public/auth/forgot-password/verify-otp';

    private string $resetUrl = '/api/public/auth/reset-password';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function activeStudent(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'password' => 'password123',
        ], $attributes));
    }

    private function resetOtpFor(User $user, string $code = '123456'): EmailVerificationOtp
    {
        return EmailVerificationOtp::factory()
            ->withCode($code)
            ->for($user)
            ->create(['purpose' => OtpPurpose::PasswordReset]);
    }

    private function resetTokenFor(User $user, string $code = '123456'): string
    {
        $this->resetOtpFor($user, $code);

        return $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => $code])
            ->assertOk()
            ->json('data.reset_token');
    }

    // ─── Forgot password ─────────────────────────────────────────────────────

    public function test_forgot_password_issues_a_password_reset_otp_for_an_active_student(): void
    {
        $user = $this->activeStudent();

        $this->postJson($this->forgotUrl, ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        $otp = $user->emailVerificationOtps()->sole();

        $this->assertSame(OtpPurpose::PasswordReset, $otp->purpose);
        $this->assertSame(OtpStatus::Pending, $otp->status);
        $this->assertSame(5, $otp->max_attempts);
        $this->assertTrue($otp->expires_at->between(now()->addMinutes(9), now()->addMinutes(11)));
        $this->assertStringStartsWith('$2y$', $otp->code_hash);

        Notification::assertSentTo($user, EmailOtpNotification::class);
    }

    public function test_forgot_password_no_longer_sends_a_reset_link(): void
    {
        $user = $this->activeStudent();

        $this->postJson($this->forgotUrl, ['email' => $user->email])->assertOk();

        Notification::assertNotSentTo($user, ResetPassword::class);
    }

    public function test_unknown_email_returns_an_identical_public_response(): void
    {
        $user = $this->activeStudent();

        $known = $this->postJson($this->forgotUrl, ['email' => $user->email])->assertOk();
        $unknown = $this->postJson($this->forgotUrl, ['email' => 'nobody@example.com'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame($known->json('success'), $unknown->json('success'));
        $this->assertSame(1, EmailVerificationOtp::count());
    }

    public function test_non_active_accounts_receive_the_same_response_without_an_otp(): void
    {
        foreach ([AccountStatus::Pending, AccountStatus::Suspended, AccountStatus::Blocked, AccountStatus::Inactive] as $status) {
            $user = $this->activeStudent([
                'status' => $status,
                'email' => mb_strtolower($status->value).'@example.com',
            ]);

            $this->postJson($this->forgotUrl, ['email' => $user->email])
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame(0, $user->emailVerificationOtps()->count());
        }

        Notification::assertNothingSent();
    }

    public function test_email_is_required(): void
    {
        $this->postJson($this->forgotUrl, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ─── Resend ──────────────────────────────────────────────────────────────

    public function test_resend_issues_a_new_reset_otp(): void
    {
        $user = $this->activeStudent();

        $this->postJson($this->resendUrl, ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(OtpPurpose::PasswordReset, $user->emailVerificationOtps()->sole()->purpose);
    }

    public function test_resend_invalidates_the_previous_pending_reset_otp(): void
    {
        $user = $this->activeStudent();
        $original = $this->resetOtpFor($user, '111111');

        $this->travel(61)->seconds();

        $this->postJson($this->resendUrl, ['email' => $user->email])->assertOk();

        $this->assertSame(OtpStatus::Expired, $original->refresh()->status);
        $this->assertSame(1, $user->emailVerificationOtps()->pending()->count());

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '111111'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID');
    }

    public function test_resend_cooldown_is_enforced(): void
    {
        $user = $this->activeStudent();

        $this->postJson($this->forgotUrl, ['email' => $user->email])->assertOk();

        $this->postJson($this->resendUrl, ['email' => $user->email])
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_RESEND_COOLDOWN');

        $this->assertSame(1, $user->emailVerificationOtps()->count());

        $this->travel(61)->seconds();

        $this->postJson($this->resendUrl, ['email' => $user->email])->assertOk();
        $this->assertSame(2, $user->emailVerificationOtps()->count());
    }

    public function test_hourly_send_limit_is_enforced(): void
    {
        $user = $this->activeStudent();

        for ($send = 1; $send <= 5; $send++) {
            $this->postJson($this->resendUrl, ['email' => $user->email])->assertOk();

            $this->travel(61)->seconds();
        }

        $this->postJson($this->resendUrl, ['email' => $user->email])
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_SEND_LIMIT_REACHED');

        $this->assertSame(5, $user->emailVerificationOtps()->count());
    }

    public function test_reset_and_verification_otps_do_not_share_limiter_buckets(): void
    {
        $user = User::factory()->unverified()->create(['status' => AccountStatus::Pending]);

        // An email-verification delivery must not put password reset on cooldown.
        $this->postJson('/api/public/auth/resend-otp', ['email' => $user->email])->assertOk();
        $this->postJson($this->resendUrl, ['email' => $user->email])->assertOk();

        $this->assertSame(
            OtpPurpose::EmailVerification,
            $user->emailVerificationOtps()->sole()->purpose,
            'The pending account is not eligible for a reset code, but the request must not be throttled.',
        );
    }

    // ─── Verify OTP ──────────────────────────────────────────────────────────

    public function test_successful_verification_returns_a_reset_token(): void
    {
        $user = $this->activeStudent();
        $this->resetOtpFor($user);

        $response = $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '123456'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['reset_token']]);

        $token = $response->json('data.reset_token');

        $this->assertIsString($token);
        $this->assertSame(64, mb_strlen($token));
        $this->assertSame(OtpStatus::Verified, $user->emailVerificationOtps()->sole()->status);

        $reset = $user->passwordResetRequests()->sole();

        $this->assertSame(hash('sha256', $token), $reset->token_hash);
        $this->assertNull($reset->used_at);
        $this->assertTrue($reset->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)));
    }

    public function test_reset_token_plaintext_is_never_persisted(): void
    {
        $user = $this->activeStudent();
        $token = $this->resetTokenFor($user);

        $this->assertSame(0, PasswordResetRequest::query()->where('token_hash', $token)->count());
        $this->assertArrayNotHasKey('token_hash', $user->passwordResetRequests()->sole()->toArray());
    }

    public function test_verification_does_not_touch_email_verification_or_status(): void
    {
        Event::fake([Verified::class]);

        $verifiedAt = now()->subMonth();
        $user = $this->activeStudent(['email_verified_at' => $verifiedAt]);
        $this->resetOtpFor($user);

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '123456'])->assertOk();

        $user->refresh();

        $this->assertSame($verifiedAt->toDateTimeString(), $user->email_verified_at->toDateTimeString());
        $this->assertSame(AccountStatus::Active, $user->status);

        Event::assertNotDispatched(Verified::class);
    }

    public function test_wrong_code_increments_attempts(): void
    {
        $user = $this->activeStudent();
        $this->resetOtpFor($user);

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID');

        $this->assertSame(1, $user->emailVerificationOtps()->sole()->attempts);
        $this->assertSame(0, $user->passwordResetRequests()->count());
    }

    public function test_max_attempts_burns_the_reset_code(): void
    {
        $user = $this->activeStudent();
        $this->resetOtpFor($user);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '000000'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'OTP_INVALID');
        }

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '000000'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_MAX_ATTEMPTS_EXCEEDED');

        $this->assertSame(OtpStatus::Failed, $user->emailVerificationOtps()->sole()->status);

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_NOT_FOUND');
    }

    public function test_expired_reset_code_is_rejected(): void
    {
        $user = $this->activeStudent();
        EmailVerificationOtp::factory()
            ->withCode('123456')
            ->expired()
            ->for($user)
            ->create(['purpose' => OtpPurpose::PasswordReset]);

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_EXPIRED');

        $this->assertSame(0, $user->passwordResetRequests()->count());
    }

    public function test_unknown_email_is_answered_like_a_wrong_code(): void
    {
        $this->postJson($this->verifyUrl, ['email' => 'nobody@example.com', 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID');
    }

    public function test_an_email_verification_otp_cannot_be_used_for_password_reset(): void
    {
        $user = $this->activeStudent();
        EmailVerificationOtp::factory()->withCode('123456')->for($user)->create();

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_NOT_FOUND');

        $this->assertSame(0, $user->passwordResetRequests()->count());
    }

    public function test_verification_requires_a_six_digit_code(): void
    {
        $user = $this->activeStudent();

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->postJson($this->verifyUrl, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'code']);
    }

    // ─── Non-active accounts cannot complete reset ───────────────────────────

    /** @return array<string, array{AccountStatus, string}> */
    public static function ineligibleStatuses(): array
    {
        return [
            'pending' => [AccountStatus::Pending, 'ACCOUNT_NOT_ACTIVE'],
            'suspended' => [AccountStatus::Suspended, 'ACCOUNT_SUSPENDED'],
            'blocked' => [AccountStatus::Blocked, 'ACCOUNT_SUSPENDED'],
            'inactive' => [AccountStatus::Inactive, 'ACCOUNT_SUSPENDED'],
        ];
    }

    #[DataProvider('ineligibleStatuses')]
    public function test_non_active_account_cannot_complete_reset_verification(
        AccountStatus $status,
        string $expectedCode,
    ): void {
        $user = $this->activeStudent(['status' => $status]);
        $otp = $this->resetOtpFor($user);

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', $expectedCode);

        $user->refresh();

        // The account must be left exactly as it was.
        $this->assertSame($status, $user->status);
        $this->assertSame(0, $user->passwordResetRequests()->count());
        $this->assertSame(OtpStatus::Pending, $otp->refresh()->status);
    }

    #[DataProvider('ineligibleStatuses')]
    public function test_non_active_account_cannot_consume_a_reset_token(
        AccountStatus $status,
        string $expectedCode,
    ): void {
        $user = $this->activeStudent();
        $token = $this->resetTokenFor($user);

        // Status changes after the token was issued but before it is used.
        $user->forceFill(['status' => $status])->save();

        $this->postJson($this->resetUrl, [
            'reset_token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(403)->assertJsonPath('code', $expectedCode);

        $this->assertTrue(Hash::check('password123', $user->refresh()->password));
    }

    // ─── Reset password ──────────────────────────────────────────────────────

    public function test_password_is_changed_with_a_valid_reset_token(): void
    {
        $user = $this->activeStudent();
        $token = $this->resetTokenFor($user);

        $this->postJson($this->resetUrl, [
            'reset_token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('newpassword123', $user->refresh()->password));
    }

    public function test_previous_password_no_longer_works_after_reset(): void
    {
        $user = $this->activeStudent();
        $token = $this->resetTokenFor($user);

        $this->postJson($this->resetUrl, [
            'reset_token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->postJson('/api/public/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');

        $this->postJson('/api/public/auth/login', [
            'email' => $user->email,
            'password' => 'newpassword123',
        ])->assertOk();
    }

    public function test_all_sanctum_tokens_are_revoked_after_reset(): void
    {
        $user = $this->activeStudent();
        $user->createToken('phone');
        $user->createToken('tablet');
        $this->assertSame(2, $user->tokens()->count());

        $token = $this->resetTokenFor($user);

        $this->postJson($this->resetUrl, [
            'reset_token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reset_token_is_single_use(): void
    {
        $user = $this->activeStudent();
        $token = $this->resetTokenFor($user);

        $payload = [
            'reset_token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ];

        $this->postJson($this->resetUrl, $payload)->assertOk();
        $this->assertNotNull($user->passwordResetRequests()->sole()->used_at);

        $this->postJson($this->resetUrl, $payload + ['password' => 'thirdpassword123'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RESET_TOKEN_USED');

        $this->assertTrue(Hash::check('newpassword123', $user->refresh()->password));
    }

    public function test_reset_token_expires_after_fifteen_minutes(): void
    {
        $user = $this->activeStudent();
        $token = $this->resetTokenFor($user);

        $this->travel(16)->minutes();

        $this->postJson($this->resetUrl, [
            'reset_token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_EXPIRED');

        $this->assertTrue(Hash::check('password123', $user->refresh()->password));
    }

    public function test_reset_token_is_still_valid_just_before_expiry(): void
    {
        $user = $this->activeStudent();
        $token = $this->resetTokenFor($user);

        $this->travel(14)->minutes();

        $this->postJson($this->resetUrl, [
            'reset_token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        $this->postJson($this->resetUrl, [
            'reset_token' => str_repeat('a', 64),
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_INVALID');
    }

    public function test_issuing_a_new_reset_token_invalidates_the_previous_one(): void
    {
        $user = $this->activeStudent();
        $first = $this->resetTokenFor($user, '111111');
        $second = $this->resetTokenFor($user, '222222');

        $this->postJson($this->resetUrl, [
            'reset_token' => $first,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(422)->assertJsonPath('code', 'RESET_TOKEN_USED');

        $this->postJson($this->resetUrl, [
            'reset_token' => $second,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();
    }

    public function test_reset_token_is_required(): void
    {
        $this->postJson($this->resetUrl, [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(422)->assertJsonValidationErrors(['reset_token']);
    }

    public function test_password_must_be_confirmed(): void
    {
        $user = $this->activeStudent();
        $token = $this->resetTokenFor($user);

        $this->postJson($this->resetUrl, [
            'reset_token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'different',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check('password123', $user->refresh()->password));
    }

    public function test_password_minimum_length_is_validated(): void
    {
        $user = $this->activeStudent();
        $token = $this->resetTokenFor($user);

        $this->postJson($this->resetUrl, [
            'reset_token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertNull($user->passwordResetRequests()->sole()->used_at);
    }
}
