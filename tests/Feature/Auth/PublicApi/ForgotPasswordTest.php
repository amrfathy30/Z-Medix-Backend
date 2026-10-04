<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Enums\OtpPurpose;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/auth/forgot-password';

    private function activeUser(): User
    {
        return User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
    }

    public function test_sends_reset_otp_for_existing_user(): void
    {
        Notification::fake();

        $user = $this->activeUser();

        $this->postJson($this->url, ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, EmailOtpNotification::class);

        $this->assertSame(
            OtpPurpose::PasswordReset,
            $user->emailVerificationOtps()->sole()->purpose,
        );
    }

    public function test_returns_safe_response_for_unknown_email(): void
    {
        Notification::fake();

        $this->postJson($this->url, ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertNothingSent();
    }

    public function test_students_no_longer_receive_a_reset_link(): void
    {
        Notification::fake();

        $user = $this->activeUser();

        $this->postJson($this->url, ['email' => $user->email])->assertOk();

        Notification::assertNotSentTo($user, ResetPassword::class);
    }

    public function test_validates_email_format(): void
    {
        $this->postJson($this->url, ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_email_is_required(): void
    {
        $this->postJson($this->url, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_is_only_exposed_on_the_public_auth_prefix(): void
    {
        // Student registration now exists; 422 proves the route is reachable and
        // validated. It must not be mirrored on the admin prefix.
        $this->postJson('/api/public/auth/register', [])->assertStatus(422);
        $this->postJson('/api/admin/auth/register', [])->assertStatus(404);
    }

    public function test_no_phone_reset_routes_exist(): void
    {
        $this->postJson('/api/public/auth/phone/reset', [])->assertStatus(404);
    }
}
