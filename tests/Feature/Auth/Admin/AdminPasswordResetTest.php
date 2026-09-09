<?php

namespace Tests\Feature\Auth\Admin;

use App\Models\Admin;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    // ─── Password Broker Config ───────────────────────────────────────────────

    public function test_admins_password_broker_is_configured(): void
    {
        $brokers = Config::get('auth.passwords');

        $this->assertArrayHasKey('admins', $brokers);
        $this->assertEquals('admins', $brokers['admins']['provider']);
        $this->assertEquals('password_reset_tokens', $brokers['admins']['table']);
    }

    public function test_admins_password_broker_resolves_correctly(): void
    {
        $broker = Password::broker('admins');

        $this->assertNotNull($broker);
        $this->assertInstanceOf(PasswordBroker::class, $broker);
    }

    // ─── Password Reset Routes ────────────────────────────────────────────────

    public function test_admin_password_reset_request_page_is_accessible(): void
    {
        $this->get(route('filament.admin.auth.password-reset.request'))
            ->assertOk();
    }

    public function test_admin_password_reset_reset_page_requires_signed_url(): void
    {
        // Without a valid signed URL, the reset page returns 403
        $this->get(route('filament.admin.auth.password-reset.reset'))
            ->assertStatus(403);
    }

    // ─── Token Creation ───────────────────────────────────────────────────────

    public function test_admin_password_reset_token_can_be_created(): void
    {
        $admin = Admin::factory()->create();

        $token = Password::broker('admins')->createToken($admin);

        $this->assertNotEmpty($token);
        $this->assertTrue(Password::broker('admins')->tokenExists($admin, $token));
    }

    public function test_admin_password_reset_token_is_stored_in_password_reset_tokens_table(): void
    {
        $admin = Admin::factory()->create();

        Password::broker('admins')->createToken($admin);

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $admin->email,
        ]);
    }
}
