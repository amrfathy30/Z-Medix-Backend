<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Models\User;
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
        ]);
    }

    public function test_sends_reset_link_for_existing_user(): void
    {
        Notification::fake();

        $user = $this->activeUser();

        $this->postJson($this->url, ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_returns_safe_response_for_unknown_email(): void
    {
        Notification::fake();

        $this->postJson($this->url, ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertNothingSent();
    }

    public function test_reset_link_points_to_frontend_url(): void
    {
        Notification::fake();

        config([
            'auth_features.password_reset.frontend_url' => 'http://frontend.test',
            'auth_features.password_reset.path' => '/reset-password',
        ]);

        $user = $this->activeUser();

        $this->postJson($this->url, ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl ?? '';

            return str_contains($url, 'http://frontend.test/reset-password');
        });
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

    public function test_no_registration_routes_exist(): void
    {
        $this->postJson('/api/public/auth/register', [])->assertStatus(404);
    }

    public function test_no_phone_reset_routes_exist(): void
    {
        $this->postJson('/api/public/auth/phone/reset', [])->assertStatus(404);
    }
}
