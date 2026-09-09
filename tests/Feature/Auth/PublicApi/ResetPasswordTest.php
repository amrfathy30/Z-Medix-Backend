<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/auth/reset-password';

    private function activeUser(): User
    {
        return User::factory()->create([
            'status' => AccountStatus::Active,
            'password' => 'old-password',
        ]);
    }

    private function validToken(User $user): string
    {
        return Password::broker('users')->createToken($user);
    }

    public function test_resets_password_with_valid_token(): void
    {
        $user = $this->activeUser();
        $token = $this->validToken($user);

        $this->postJson($this->url, [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_updates_user_password(): void
    {
        $user = $this->activeUser();
        $token = $this->validToken($user);

        $this->postJson($this->url, [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_old_password_no_longer_works_after_reset(): void
    {
        $user = $this->activeUser();
        $token = $this->validToken($user);

        $this->postJson($this->url, [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertFalse(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_existing_sanctum_tokens_are_revoked_after_reset(): void
    {
        $user = $this->activeUser();
        $user->createToken('api');
        $user->createToken('mobile');
        $this->assertEquals(2, $user->tokens()->count());

        $token = $this->validToken($user);

        $this->postJson($this->url, [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertEquals(0, $user->tokens()->count());
    }

    public function test_fails_with_invalid_token(): void
    {
        $user = $this->activeUser();

        $this->postJson($this->url, [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);
    }

    public function test_fails_when_password_confirmation_does_not_match(): void
    {
        $user = $this->activeUser();
        $token = $this->validToken($user);

        $this->postJson($this->url, [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_validates_password_minimum_length(): void
    {
        $user = $this->activeUser();
        $token = $this->validToken($user);

        $this->postJson($this->url, [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_token_field_is_required(): void
    {
        $user = $this->activeUser();

        $this->postJson($this->url, [
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }
}
