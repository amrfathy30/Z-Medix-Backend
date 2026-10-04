<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Enums\OtpStatus;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EmailOtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/auth/verify-otp';

    private function pendingStudent(string $code = '123456'): User
    {
        $user = User::factory()->unverified()->create([
            'status' => AccountStatus::Pending,
        ]);

        EmailVerificationOtp::factory()->withCode($code)->for($user)->create();

        return $user;
    }

    public function test_correct_code_verifies_the_email_and_activates_the_account(): void
    {
        Event::fake([Verified::class]);

        $user = $this->pendingStudent();

        $this->postJson($this->url, ['email' => $user->email, 'code' => '123456'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();

        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(AccountStatus::Active, $user->status);

        Event::assertDispatched(Verified::class);
    }

    public function test_successful_verification_marks_the_code_verified(): void
    {
        $user = $this->pendingStudent();

        $this->postJson($this->url, ['email' => $user->email, 'code' => '123456'])->assertOk();

        $otp = $user->emailVerificationOtps()->sole();

        $this->assertSame(OtpStatus::Verified, $otp->status);
        $this->assertNotNull($otp->verified_at);
        $this->assertSame(1, $otp->attempts);
    }

    public function test_wrong_code_increments_attempts_and_leaves_account_pending(): void
    {
        $user = $this->pendingStudent();

        $this->postJson($this->url, ['email' => $user->email, 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'OTP_INVALID');

        $otp = $user->emailVerificationOtps()->sole();

        $this->assertSame(1, $otp->attempts);
        $this->assertSame(OtpStatus::Pending, $otp->status);

        $user->refresh();
        $this->assertNull($user->email_verified_at);
        $this->assertSame(AccountStatus::Pending, $user->status);
    }

    public function test_code_is_burned_after_the_maximum_attempts(): void
    {
        $user = $this->pendingStudent();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson($this->url, ['email' => $user->email, 'code' => '000000'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'OTP_INVALID');
        }

        // The fifth wrong submission exhausts the allowance and fails the code.
        $this->postJson($this->url, ['email' => $user->email, 'code' => '000000'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_MAX_ATTEMPTS_EXCEEDED');

        $otp = $user->emailVerificationOtps()->sole();

        $this->assertSame(5, $otp->attempts);
        $this->assertSame(OtpStatus::Failed, $otp->status);

        // Even the correct code no longer works once the record is burned.
        $this->postJson($this->url, ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_NOT_FOUND');

        $this->assertSame(AccountStatus::Pending, $user->refresh()->status);
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = User::factory()->unverified()->create(['status' => AccountStatus::Pending]);
        EmailVerificationOtp::factory()->withCode('123456')->expired()->for($user)->create();

        $this->postJson($this->url, ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_EXPIRED');

        $this->assertSame(OtpStatus::Expired, $user->emailVerificationOtps()->sole()->status);
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_code_expires_exactly_after_the_configured_window(): void
    {
        $user = $this->pendingStudent();

        $this->travel(11)->minutes();

        $this->postJson($this->url, ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_EXPIRED');
    }

    public function test_verification_without_a_pending_code_is_rejected(): void
    {
        $user = User::factory()->unverified()->create(['status' => AccountStatus::Pending]);

        $this->postJson($this->url, ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_NOT_FOUND');
    }

    public function test_unknown_email_is_answered_like_a_wrong_code(): void
    {
        $this->postJson($this->url, ['email' => 'nobody@example.com', 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_INVALID');
    }

    public function test_already_verified_account_returns_a_no_op_success(): void
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $this->postJson($this->url, ['email' => $user->email, 'code' => '123456'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_code_must_be_six_digits(): void
    {
        $user = $this->pendingStudent();

        $this->postJson($this->url, ['email' => $user->email, 'code' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->postJson($this->url, ['email' => $user->email, 'code' => 'abcdef'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->assertSame(0, $user->emailVerificationOtps()->sole()->attempts);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->postJson($this->url, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'code']);
    }

    public function test_verified_student_can_then_login(): void
    {
        $user = User::factory()->unverified()->create([
            'status' => AccountStatus::Pending,
            'password' => 'password',
        ]);
        EmailVerificationOtp::factory()->withCode('123456')->for($user)->create();

        $this->postJson($this->url, ['email' => $user->email, 'code' => '123456'])->assertOk();

        $this->postJson('/api/public/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.is_email_verified', true)
            ->assertJsonPath('data.user.status', AccountStatus::Active->value)
            ->assertJsonStructure(['data' => ['token']]);
    }
}
