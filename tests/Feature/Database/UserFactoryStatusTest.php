<?php

namespace Tests\Feature\Database;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFactoryStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_user_uses_the_pending_database_default(): void
    {
        $user = User::factory()->create();

        $this->assertSame(AccountStatus::Pending, $user->status);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => AccountStatus::Pending->value,
        ]);
        $this->assertSame(AccountStatus::Pending, $user->fresh()->status);
    }

    public function test_an_explicit_status_still_wins(): void
    {
        $user = User::factory()->create(['status' => AccountStatus::Active]);

        $this->assertSame(AccountStatus::Active, $user->fresh()->status);
    }
}
