<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestOtpCodeTest extends TestCase
{
    use RefreshDatabase;

    private string $verifyUrl = '/api/public/auth/verify-otp';

    private string $resetVerifyUrl = '/api/public/auth/forgot-password/verify-otp';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auth_features.email_otp.test_code' => '999999',
            'auth_features.email_otp.test_code_environments' => ['local', 'testing', 'staging'],
        ]);
    }

    private function pendingStudent(): User
    {
        $user = User::factory()->unverified()->create(['status' => AccountStatus::Pending]);

        EmailVerificationOtp::factory()->withCode('123456')->for($user)->create();

        return $user;
    }

    private function activeStudentAwaitingReset(): User
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        EmailVerificationOtp::factory()
            ->withCode('123456')
            ->for($user)
            ->create(['purpose' => OtpPurpose::PasswordReset]);

        return $user;
    }

    // ─── Accepted ────────────────────────────────────────────────────────────

    public function test_test_code_is_accepted_in_the_testing_environment(): void
    {
        $user = $this->pendingStudent();

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();

        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(AccountStatus::Active, $user->status);
    }

    public function test_test_code_works_for_the_password_reset_purpose(): void
    {
        $user = $this->activeStudentAwaitingReset();

        $this->postJson($this->resetVerifyUrl, ['email' => $user->email, 'code' => '999999'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['reset_token']]);
    }

    public function test_the_real_code_still_works_while_the_test_code_is_enabled(): void
    {
        $user = $this->pendingStudent();

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '123456'])
            ->assertOk();
    }

    // ─── Fails closed ────────────────────────────────────────────────────────

    public function test_test_code_is_rejected_when_the_environment_is_not_allow_listed(): void
    {
        config(['auth_features.email_otp.test_code_environments' => ['local', 'staging']]);

        $user = $this->pendingStudent();

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID');

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_test_code_is_rejected_when_the_code_is_not_configured(): void
    {
        config(['auth_features.email_otp.test_code' => null]);

        $user = $this->pendingStudent();

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID');
    }

    public function test_test_code_is_rejected_when_the_environment_list_is_empty(): void
    {
        config(['auth_features.email_otp.test_code_environments' => []]);

        $user = $this->pendingStudent();

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID');
    }

    public function test_test_code_is_rejected_when_the_config_is_malformed(): void
    {
        foreach ([['test_code' => ''], ['test_code' => 999999], ['test_code_environments' => 'testing']] as $override) {
            config(['auth_features.email_otp.test_code' => '999999']);
            config(['auth_features.email_otp.test_code_environments' => ['testing']]);

            foreach ($override as $key => $value) {
                config(["auth_features.email_otp.{$key}" => $value]);
            }

            $user = $this->pendingStudent();

            $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'OTP_INVALID');
        }
    }

    public function test_test_code_is_refused_in_production_even_when_production_is_allow_listed(): void
    {
        $user = $this->pendingStudent();

        // Both the accidental allow-list entry and the running environment.
        config(['auth_features.email_otp.test_code_environments' => ['local', 'testing', 'production']]);
        app()->detectEnvironment(fn (): string => 'production');

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID');

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_listing_production_disables_the_test_code_in_every_environment(): void
    {
        config(['auth_features.email_otp.test_code_environments' => ['testing', 'production']]);

        $user = $this->pendingStudent();

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID');
    }

    // ─── Still bound by the real OTP record ──────────────────────────────────

    public function test_test_code_requires_a_real_pending_otp(): void
    {
        $user = User::factory()->unverified()->create(['status' => AccountStatus::Pending]);

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_NOT_FOUND');

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_test_code_still_respects_expiry(): void
    {
        $user = User::factory()->unverified()->create(['status' => AccountStatus::Pending]);
        EmailVerificationOtp::factory()->withCode('123456')->expired()->for($user)->create();

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_EXPIRED');

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_test_code_still_respects_max_attempts(): void
    {
        $user = User::factory()->unverified()->create(['status' => AccountStatus::Pending]);
        EmailVerificationOtp::factory()
            ->withCode('123456')
            ->for($user)
            ->create(['attempts' => 5, 'max_attempts' => 5]);

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_MAX_ATTEMPTS_EXCEEDED');

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_test_code_still_increments_the_attempt_counter(): void
    {
        $user = $this->pendingStudent();

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])->assertOk();

        $this->assertSame(1, $user->emailVerificationOtps()->sole()->attempts);
    }

    public function test_test_code_cannot_revive_a_burned_otp(): void
    {
        $user = $this->pendingStudent();

        EmailVerificationOtp::query()->update(['status' => OtpStatus::Failed]);

        $this->postJson($this->verifyUrl, ['email' => $user->email, 'code' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_NOT_FOUND');
    }
}
