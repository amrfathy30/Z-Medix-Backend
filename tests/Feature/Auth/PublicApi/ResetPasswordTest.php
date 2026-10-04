<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Enums\OtpPurpose;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Student password reset is OTP based: the reset token comes from verifying a
 * PasswordReset OTP, not from Laravel's `users` password broker.
 *
 * End-to-end coverage of the whole flow lives in PasswordResetOtpTest; these
 * cases preserve the original assertions of this file against the new contract.
 */
class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/auth/reset-password';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function activeUser(): User
    {
        return User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'password' => 'old-password',
        ]);
    }

    private function validToken(User $user): string
    {
        EmailVerificationOtp::factory()
            ->withCode('123456')
            ->for($user)
            ->create(['purpose' => OtpPurpose::PasswordReset]);

        return $this->postJson('/api/public/auth/forgot-password/verify-otp', [
            'email' => $user->email,
            'code' => '123456',
        ])->assertOk()->json('data.reset_token');
    }

    public function test_resets_password_with_valid_token(): void
    {
        $user = $this->activeUser();

        $this->postJson($this->url, [
            'reset_token' => $this->validToken($user),
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_updates_user_password(): void
    {
        $user = $this->activeUser();

        $this->postJson($this->url, [
            'reset_token' => $this->validToken($user),
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_old_password_no_longer_works_after_reset(): void
    {
        $user = $this->activeUser();

        $this->postJson($this->url, [
            'reset_token' => $this->validToken($user),
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

        $this->postJson($this->url, [
            'reset_token' => $this->validToken($user),
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertEquals(0, $user->tokens()->count());
    }

    public function test_fails_with_invalid_token(): void
    {
        $this->activeUser();

        $this->postJson($this->url, [
            'reset_token' => 'invalid-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'RESET_TOKEN_INVALID');
    }

    public function test_fails_when_password_confirmation_does_not_match(): void
    {
        $user = $this->activeUser();

        $this->postJson($this->url, [
            'reset_token' => $this->validToken($user),
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_validates_password_minimum_length(): void
    {
        $user = $this->activeUser();

        $this->postJson($this->url, [
            'reset_token' => $this->validToken($user),
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_token_field_is_required(): void
    {
        $this->activeUser();

        $this->postJson($this->url, [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['reset_token']);
    }

    public function test_the_users_password_broker_is_no_longer_part_of_the_student_flow(): void
    {
        $user = $this->activeUser();
        $brokerToken = Password::broker('users')->createToken($user);

        $this->postJson($this->url, [
            'reset_token' => $brokerToken,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'RESET_TOKEN_INVALID');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }
}
