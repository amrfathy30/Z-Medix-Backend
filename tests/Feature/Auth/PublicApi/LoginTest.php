<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Enums\PhoneStatus;
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

    public function test_pending_user_cannot_login_even_when_email_is_verified(): void
    {
        $user = $this->activeUser([
            'status' => AccountStatus::Pending,
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ACCOUNT_NOT_ACTIVE');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_pending_and_unverified_user_cannot_login(): void
    {
        $user = User::factory()->unverified()->create([
            'status' => AccountStatus::Pending,
            'password' => 'password',
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

        $this->assertSame(0, $user->tokens()->count());
    }

    // ─── Email verification gating ───────────────────────────────────────────

    public function test_active_but_unverified_student_cannot_login(): void
    {
        $user = User::factory()->unverified()->create([
            'status' => AccountStatus::Active,
            'password' => 'password',
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    }

    public function test_unverified_login_does_not_issue_a_token_or_touch_last_login_at(): void
    {
        $user = User::factory()->unverified()->create([
            'status' => AccountStatus::Active,
            'password' => 'password',
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonMissingPath('data.token');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_verified_and_active_student_can_login(): void
    {
        $user = $this->activeUser([
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.is_email_verified', true)
            ->assertJsonPath('data.user.status', AccountStatus::Active->value)
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_account_status_rejection_takes_precedence_over_email_verification(): void
    {
        $user = User::factory()->unverified()->create([
            'status' => AccountStatus::Blocked,
            'password' => 'password',
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    public function test_unverified_phone_does_not_block_login(): void
    {
        $user = $this->activeUser([
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $user->phoneNumbers()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'country_iso2' => 'EG',
            'country_code' => '+20',
            'national_number' => '1001234567',
            'e164_number' => '+201001234567',
            'is_primary' => true,
            'status' => PhoneStatus::Pending,
        ]);

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_registration_is_only_exposed_on_the_public_auth_prefix(): void
    {
        // Student registration now exists; 422 proves the route is reachable and
        // validated. It must not be mirrored on the admin prefix.
        $this->postJson('/api/public/auth/register', [])->assertStatus(422);
        $this->postJson('/api/admin/auth/register', [])->assertStatus(404);
    }

    public function test_no_phone_login_routes_exist(): void
    {
        $this->postJson('/api/public/auth/phone/login', [])->assertStatus(404);
        $this->postJson('/api/public/auth/phone/verify-otp', [])->assertStatus(404);
    }
}
