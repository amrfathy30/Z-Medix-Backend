<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/auth/login';

    private function activeUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => AccountStatus::Active,
        ], $attributes));
    }

    public function test_active_user_can_login(): void
    {
        $user = $this->activeUser(['password' => 'password']);

        $response = $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'user' => ['id', 'name', 'email', 'is_email_verified', 'type', 'status'],
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.status', AccountStatus::Active->value);
    }

    public function test_login_sets_last_login_at(): void
    {
        $user = $this->activeUser(['password' => 'password']);

        $this->assertNull($user->last_login_at);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_fails_with_401(): void
    {
        $user = $this->activeUser();

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_unknown_email_fails_with_401(): void
    {
        $this->postJson($this->url, [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])->assertStatus(401);
    }

    public function test_missing_fields_fail_with_422(): void
    {
        $this->postJson($this->url, [])->assertStatus(422);
    }

    public function test_suspended_user_cannot_login(): void
    {
        $user = $this->activeUser([
            'status' => AccountStatus::Suspended,
            'password' => 'password',
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_blocked_user_cannot_login(): void
    {
        $user = $this->activeUser([
            'status' => AccountStatus::Blocked,
            'password' => 'password',
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->activeUser([
            'status' => AccountStatus::Inactive,
            'password' => 'password',
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_pending_user_can_login_when_config_allows(): void
    {
        config(['auth_features.login.allow_pending_users' => true]);

        $user = $this->activeUser([
            'status' => AccountStatus::Pending,
            'password' => 'password',
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_pending_user_cannot_login_when_config_blocks(): void
    {
        config(['auth_features.login.allow_pending_users' => false]);

        $user = $this->activeUser([
            'status' => AccountStatus::Pending,
            'password' => 'password',
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_no_registration_routes_exist(): void
    {
        $this->postJson('/api/public/auth/register', [])->assertStatus(404);
    }

    public function test_no_phone_login_routes_exist(): void
    {
        $this->postJson('/api/public/auth/phone/login', [])->assertStatus(404);
        $this->postJson('/api/public/auth/otp/verify', [])->assertStatus(404);
    }
}
