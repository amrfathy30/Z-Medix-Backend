<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Enums\OtpStatus;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ResendEmailOtpTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/auth/resend-otp';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function pendingStudent(): User
    {
        return User::factory()->unverified()->create([
            'status' => AccountStatus::Pending,
        ]);
    }

    public function test_resend_issues_a_new_pending_code(): void
    {
        $user = $this->pendingStudent();

        $this->postJson($this->url, ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        $otp = $user->emailVerificationOtps()->sole();

        $this->assertSame(OtpStatus::Pending, $otp->status);
        $this->assertStringStartsWith('$2y$', $otp->code_hash);

        Notification::assertSentTo($user, EmailOtpNotification::class);
    }

    public function test_resend_invalidates_the_previous_pending_code(): void
    {
        $user = $this->pendingStudent();
        $original = EmailVerificationOtp::factory()->withCode('111111')->for($user)->create();

        $this->travel(61)->seconds();

        $this->postJson($this->url, ['email' => $user->email])->assertOk();

        $this->assertSame(OtpStatus::Expired, $original->refresh()->status);
        $this->assertSame(1, $user->emailVerificationOtps()->pending()->count());

        // The superseded code can no longer activate the account.
        $this->postJson('/api/public/auth/verify-otp', [
            'email' => $user->email,
            'code' => '111111',
        ])->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');

        $this->assertSame(AccountStatus::Pending, $user->refresh()->status);
    }

    public function test_resend_cooldown_is_enforced(): void
    {
        $user = $this->pendingStudent();

        $this->postJson($this->url, ['email' => $user->email])->assertOk();

        $this->postJson($this->url, ['email' => $user->email])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'OTP_RESEND_COOLDOWN');

        $this->assertSame(1, $user->emailVerificationOtps()->count());

        $this->travel(61)->seconds();

        $this->postJson($this->url, ['email' => $user->email])->assertOk();

        $this->assertSame(2, $user->emailVerificationOtps()->count());
    }

    public function test_hourly_send_limit_is_enforced(): void
    {
        $user = $this->pendingStudent();

        // Five deliveries are allowed per rolling hour, each separated by the cooldown.
        for ($send = 1; $send <= 5; $send++) {
            $this->postJson($this->url, ['email' => $user->email])->assertOk();

            $this->travel(61)->seconds();
        }

        $this->postJson($this->url, ['email' => $user->email])
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_SEND_LIMIT_REACHED');

        $this->assertSame(5, $user->emailVerificationOtps()->count());
    }

    public function test_registration_delivery_counts_towards_the_hourly_limit(): void
    {
        $egypt = $this->createCountry('EG', 'Egypt', '20');

        $this->postJson('/api/public/auth/register', [
            'full_name' => 'Mona Saleh',
            'email' => 'mona@example.com',
            'phone' => '+201001234567',
            'country_id' => $egypt->id,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::where('email', 'mona@example.com')->sole();

        // The registration delivery is the first of the five allowed per hour.
        for ($resend = 1; $resend <= 4; $resend++) {
            $this->travel(61)->seconds();

            $this->postJson($this->url, ['email' => $user->email])->assertOk();
        }

        $this->travel(61)->seconds();

        $this->postJson($this->url, ['email' => $user->email])
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_SEND_LIMIT_REACHED');

        $this->assertSame(5, $user->emailVerificationOtps()->count());
    }

    public function test_unknown_email_returns_a_generic_success_without_issuing_a_code(): void
    {
        $this->postJson($this->url, ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, EmailVerificationOtp::count());
        Notification::assertNothingSent();
    }

    public function test_already_verified_email_returns_the_same_generic_success(): void
    {
        $verified = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $unknown = $this->postJson($this->url, ['email' => 'nobody@example.com'])->assertOk();
        $known = $this->postJson($this->url, ['email' => $verified->email])->assertOk();

        $this->assertSame($unknown->json('message'), $known->json('message'));
        $this->assertSame(0, EmailVerificationOtp::count());
        Notification::assertNothingSent();
    }

    public function test_unknown_email_still_consumes_the_cooldown(): void
    {
        $this->postJson($this->url, ['email' => 'nobody@example.com'])->assertOk();

        $this->postJson($this->url, ['email' => 'nobody@example.com'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_RESEND_COOLDOWN');
    }

    public function test_email_is_required(): void
    {
        $this->postJson($this->url, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
