<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => AccountStatus::Active,
        ], $attributes));
    }

    // ─── Show ─────────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_view_profile(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'name', 'first_name', 'last_name', 'email', 'is_email_verified', 'type', 'status', 'last_login_at'],
            ])
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_unauthenticated_user_cannot_view_profile(): void
    {
        $this->getJson('/api/public/profile')->assertStatus(401);
    }

    public function test_profile_response_does_not_include_avatar(): void
    {
        Sanctum::actingAs($this->user());

        $data = $this->getJson('/api/public/profile')->assertOk()->json('data');

        $this->assertArrayNotHasKey('avatar', $data);
        $this->assertArrayNotHasKey('avatar_url', $data);
    }

    public function test_profile_response_does_not_include_student_or_lecturer_details(): void
    {
        Sanctum::actingAs($this->user());

        $data = $this->getJson('/api/public/profile')->assertOk()->json('data');

        $this->assertArrayNotHasKey('student', $data);
        $this->assertArrayNotHasKey('lecturer', $data);
        $this->assertArrayNotHasKey('university', $data);
        $this->assertArrayNotHasKey('bio', $data);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function test_profile_response_includes_first_and_last_name(): void
    {
        $user = $this->user(['first_name' => 'Alice', 'last_name' => 'Wonder']);
        Sanctum::actingAs($user);

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Alice')
            ->assertJsonPath('data.last_name', 'Wonder');
    }

    public function test_authenticated_user_can_update_first_and_last_name(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['first_name' => 'Bob', 'last_name' => 'Builder'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Bob')
            ->assertJsonPath('data.last_name', 'Builder');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Bob',
            'last_name' => 'Builder',
        ]);
    }

    public function test_authenticated_user_can_update_name_only(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', $user->email);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
    }

    public function test_authenticated_user_can_update_email_only(): void
    {
        Notification::fake();

        $user = $this->user(['email_verified_at' => now()]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['email' => 'new@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com');
    }

    public function test_authenticated_user_can_update_name_and_email(): void
    {
        Notification::fake();

        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'updated@example.com');
    }

    public function test_empty_profile_update_request_is_allowed(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', [])
            ->assertOk()
            ->assertJsonPath('data.name', $user->name);
    }

    public function test_invalid_email_fails_validation(): void
    {
        Sanctum::actingAs($this->user());

        $this->patchJson('/api/public/profile', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_duplicate_email_fails_validation(): void
    {
        $existing = $this->user(['email' => 'taken@example.com']);
        Sanctum::actingAs($this->user());

        $this->patchJson('/api/public/profile', ['email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_unchanged_email_does_not_reset_email_verified_at(): void
    {
        $user = $this->user(['email_verified_at' => now()]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['email' => $user->email])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_changed_email_resets_email_verified_at(): void
    {
        Notification::fake();

        $user = $this->user(['email_verified_at' => now()]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['email' => 'changed@example.com'])->assertOk();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_changed_email_sends_verification_notification(): void
    {
        Notification::fake();

        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['email' => 'changed@example.com'])->assertOk();

        // The notification is sent to the user at their new email
        $user->refresh();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    // ─── Guard rails ─────────────────────────────────────────────────────────

    public function test_no_registration_routes_exist(): void
    {
        $this->postJson('/api/public/auth/register', [])->assertStatus(404);
    }

    public function test_no_phone_routes_exist(): void
    {
        $this->postJson('/api/public/auth/phone/login', [])->assertStatus(404);
    }

    public function test_no_admin_profile_routes_exist(): void
    {
        $this->getJson('/api/admin/profile')->assertStatus(404);
    }
}
