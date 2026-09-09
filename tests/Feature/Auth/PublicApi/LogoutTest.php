<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/auth/logout';

    private function activeUser(): User
    {
        return User::factory()->create([
            'status' => AccountStatus::Active,
        ]);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = $this->activeUser();
        Sanctum::actingAs($user);

        $this->postJson($this->url)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = $this->activeUser();
        $token = $user->createToken('api')->plainTextToken;

        $this->assertEquals(1, $user->tokens()->count());

        $this->postJson($this->url, [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $this->assertEquals(0, $user->tokens()->count());
    }

    public function test_unauthenticated_logout_fails(): void
    {
        $this->postJson($this->url)->assertStatus(401);
    }
}
