<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/auth/change-password';

    private function user(string $password = 'old-password-123'): User
    {
        return User::factory()->create([
            'status' => AccountStatus::Active,
            'password' => $password,
        ]);
    }

    public function test_authenticated_user_can_change_password(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->postJson($this->url, [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_new_password_works_after_change(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->postJson($this->url, [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_old_password_no_longer_works_after_change(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->postJson($this->url, [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertFalse(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_wrong_current_password_fails(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->postJson($this->url, [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
    }

    public function test_password_confirmation_mismatch_fails(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->postJson($this->url, [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_unauthenticated_user_cannot_change_password(): void
    {
        $this->postJson($this->url, [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(401);
    }

    /**
     * After password change the current device's token is kept; all other tokens are revoked.
     * This lets the user stay logged in on the device where they changed the password.
     * Note: withToken() is used (not Sanctum::actingAs) so currentAccessToken() resolves
     * a real PersonalAccessToken instance from the database, enabling selective revocation.
     */
    public function test_other_tokens_are_revoked_after_password_change(): void
    {
        $user = $this->user();

        $user->createToken('other-device-1');
        $user->createToken('other-device-2');
        $currentToken = $user->createToken('this-device');

        $this->withToken($currentToken->plainTextToken)
            ->postJson($this->url, [
                'current_password' => 'old-password-123',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])->assertOk();

        $this->assertEquals(1, $user->tokens()->count());
    }

    public function test_current_token_remains_valid_after_password_change(): void
    {
        $user = $this->user();
        $currentToken = $user->createToken('this-device');

        $this->withToken($currentToken->plainTextToken)
            ->postJson($this->url, [
                'current_password' => 'old-password-123',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])->assertOk();

        $this->assertEquals(1, $user->tokens()->count());
    }
}
