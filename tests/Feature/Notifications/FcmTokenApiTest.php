<?php

namespace Tests\Feature\Notifications;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FcmTokenApiTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/student/fcm-tokens';

    public function test_guest_cannot_register_or_delete_tokens(): void
    {
        $this->postJson($this->endpoint, ['token' => 'some-token'])->assertUnauthorized();
        $this->deleteJson($this->endpoint, ['token' => 'some-token'])->assertUnauthorized();
    }

    public function test_token_is_required_to_register(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson($this->endpoint, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);
    }

    public function test_device_type_must_be_valid(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson($this->endpoint, [
            'token' => 'some-token',
            'device_type' => 'windows_phone',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_type']);
    }

    public function test_user_can_register_fcm_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson($this->endpoint, [
            'token' => 'fcm-registration-token-123',
            'device_type' => 'ios',
            'device_id' => 'iphone-device-id',
            'app_type' => 'student_app',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token', 'fcm-registration-token-123');

        $this->assertDatabaseHas('fcm_tokens', [
            'tokenable_type' => 'user',
            'tokenable_id' => $user->id,
            'token' => 'fcm-registration-token-123',
            'device_type' => 'ios',
            'device_id' => 'iphone-device-id',
            'app_type' => 'student_app',
            'is_active' => true,
        ]);
    }

    public function test_user_can_delete_their_own_fcm_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson($this->endpoint, [
            'token' => 'token-to-delete',
        ])->assertCreated();

        $this->deleteJson($this->endpoint, ['token' => 'token-to-delete'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('fcm_tokens', [
            'token' => 'token-to-delete',
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_user_cannot_delete_another_users_fcm_token(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Sanctum::actingAs($user1);
        $this->postJson($this->endpoint, ['token' => 'token-user1'])->assertCreated();

        Sanctum::actingAs($user2);
        $this->deleteJson($this->endpoint, ['token' => 'token-user1'])
            ->assertNotFound();

        $this->assertDatabaseHas('fcm_tokens', [
            'token' => 'token-user1',
            'deleted_at' => null,
        ]);
    }
}
