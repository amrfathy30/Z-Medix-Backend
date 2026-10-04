<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function unverifiedUser(): User
    {
        return User::factory()->unverified()->create([
            'status' => AccountStatus::Active,
        ]);
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
    }

    private function verifyUrl(User $user, bool $valid = true): string
    {
        if (! $valid) {
            return route('public.auth.verify-email', [
                'id' => $user->id,
                'hash' => 'wrong-hash',
            ]).'&expires=9999999999&signature=fakesig';
        }

        return URL::temporarySignedRoute(
            'public.auth.verify-email',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );
    }

    // ─── Model ───────────────────────────────────────────────────────────────

    public function test_user_model_implements_must_verify_email(): void
    {
        $this->assertInstanceOf(MustVerifyEmail::class, new User);
    }

    // ─── Resend ──────────────────────────────────────────────────────────────

    public function test_unverified_user_can_request_verification_email(): void
    {
        Notification::fake();

        $user = $this->unverifiedUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/public/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_verified_user_resend_returns_no_op_success(): void
    {
        Notification::fake();

        $user = $this->verifiedUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/public/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_resend_requires_authentication(): void
    {
        $this->postJson('/api/public/auth/email/verification-notification')
            ->assertStatus(401);
    }

    // ─── Notification URL ────────────────────────────────────────────────────

    public function test_verification_notification_contains_signed_api_url(): void
    {
        Notification::fake();

        $user = $this->unverifiedUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/public/auth/email/verification-notification')->assertOk();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $url = $mail->actionUrl ?? '';

            return str_contains($url, '/api/public/auth/verify-email/')
                && str_contains($url, 'signature=')
                && str_contains($url, 'expires=');
        });
    }

    // ─── Verify ──────────────────────────────────────────────────────────────

    public function test_valid_signed_url_verifies_email(): void
    {
        $user = $this->unverifiedUser();
        $url = $this->verifyUrl($user);

        $this->getJson($url)->assertOk()->assertJsonPath('success', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_successful_verification_redirects_to_frontend_success_url(): void
    {
        config([
            'auth_features.password_reset.frontend_url' => 'http://frontend.test',
            'auth_features.email_verification.success_path' => '/email/verified?status=success',
        ]);

        $user = $this->unverifiedUser();
        $url = $this->verifyUrl($user);

        $this->get($url)
            ->assertRedirect('http://frontend.test/email/verified?status=success');
    }

    public function test_invalid_hash_redirects_to_frontend_failed_url(): void
    {
        config([
            'auth_features.password_reset.frontend_url' => 'http://frontend.test',
            'auth_features.email_verification.failed_path' => '/email/verified?status=failed',
        ]);

        $user = $this->unverifiedUser();

        // Build a signed URL but with a wrong hash
        $url = URL::temporarySignedRoute(
            'public.auth.verify-email',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => 'wrong-hash']
        );

        $this->get($url)
            ->assertRedirect('http://frontend.test/email/verified?status=failed');
    }

    public function test_unsigned_url_redirects_to_frontend_failed_url(): void
    {
        config([
            'auth_features.password_reset.frontend_url' => 'http://frontend.test',
            'auth_features.email_verification.failed_path' => '/email/verified?status=failed',
        ]);

        $user = $this->unverifiedUser();

        // Plain route URL without signature
        $url = route('public.auth.verify-email', [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($url)
            ->assertRedirect('http://frontend.test/email/verified?status=failed');
    }

    public function test_invalid_hash_returns_json_error_when_json_requested(): void
    {
        $user = $this->unverifiedUser();

        $url = URL::temporarySignedRoute(
            'public.auth.verify-email',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => 'wrong-hash']
        );

        $this->getJson($url)->assertStatus(422)->assertJsonPath('success', false);
    }

    // ─── Login gating ────────────────────────────────────────────────────────

    public function test_login_is_rejected_for_an_unverified_user(): void
    {
        $user = $this->unverifiedUser();
        $user->update(['password' => 'password']);

        $this->postJson('/api/public/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    }

    public function test_login_succeeds_once_the_signed_link_has_verified_the_email(): void
    {
        $user = $this->unverifiedUser();
        $user->update(['password' => 'password']);

        $this->getJson($this->verifyUrl($user))->assertOk();

        $this->postJson('/api/public/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.is_email_verified', true);
    }

    // ─── Guard rails ─────────────────────────────────────────────────────────

    public function test_registration_is_only_exposed_on_the_public_auth_prefix(): void
    {
        // Student registration now exists; 422 proves the route is reachable and
        // validated. It must not be mirrored on the admin prefix.
        $this->postJson('/api/public/auth/register', [])->assertStatus(422);
        $this->postJson('/api/admin/auth/register', [])->assertStatus(404);
    }

    public function test_no_phone_verification_routes_exist(): void
    {
        $this->postJson('/api/public/auth/phone/verify', [])->assertStatus(404);
    }

    public function test_no_admin_email_verification_routes_exist(): void
    {
        $this->postJson('/api/admin/auth/email/verification-notification', [])->assertStatus(404);
    }
}
