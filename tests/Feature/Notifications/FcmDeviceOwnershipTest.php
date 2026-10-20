<?php

namespace Tests\Feature\Notifications;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FcmDeviceOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_X = 'device-handset-x';
    private const DEVICE_Y = 'device-tablet-y';

    private string $registerUrl = '/api/student/fcm-tokens';
    private string $logoutUrl = '/api/public/auth/logout';

    private function student(): User
    {
        return User::factory()->create();
    }

    private function register(User $user, string $token, ?string $deviceId = null): void
    {
        Sanctum::actingAs($user);

        $payload = [
            'token' => $token,
            'device_type' => 'android',
        ];

        if ($deviceId !== null) {
            $payload['device_id'] = $deviceId;
        }

        $this->postJson($this->registerUrl, $payload)->assertCreated();
    }

    /**
     * @return array<int, string>
     */
    private function deliveryTargets(User $user): array
    {
        return $user->fcmTokens()
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('token')
            ->all();
    }

    public function test_re_registering_the_same_token_and_device_is_idempotent(): void
    {
        $a = $this->student();

        $this->register($a, 'token-a', self::DEVICE_X);
        $this->register($a, 'token-a', self::DEVICE_X);
        $this->register($a, 'token-a', self::DEVICE_X);

        $this->assertSame(1, FcmToken::withTrashed()->count());
        $this->assertSame(['token-a'], $this->deliveryTargets($a));
    }

    public function test_token_rotation_on_the_same_device_retires_the_old_token(): void
    {
        $a = $this->student();

        $this->register($a, 'token-old', self::DEVICE_X);
        $this->register($a, 'token-new', self::DEVICE_X);

        $this->assertSame(['token-new'], $this->deliveryTargets($a));
        $this->assertDatabaseHas('fcm_tokens', ['token' => 'token-old', 'is_active' => false]);
        $this->assertDatabaseHas('fcm_tokens', ['token' => 'token-old', 'deleted_at' => null]);
    }

    public function test_account_switch_on_a_device_takes_ownership_away_from_the_previous_user(): void
    {
        $a = $this->student();
        $b = $this->student();

        // Step 1 — A owns Device X.
        $this->register($a, 'token-a', self::DEVICE_X);
        $this->assertSame(['token-a'], $this->deliveryTargets($a));

        // Step 2 — B registers on the SAME device, with no logout from A.
        $this->register($b, 'token-b', self::DEVICE_X);

        // Step 3 — Device X is no longer a delivery target for A.
        $this->assertSame([], $this->deliveryTargets($a));
        $this->assertDatabaseHas('fcm_tokens', [
            'token' => 'token-a',
            'tokenable_id' => $a->id,
            'is_active' => false,
        ]);

        // Step 4 — Device X is a delivery target for B.
        $this->assertSame(['token-b'], $this->deliveryTargets($b));
    }

    public function test_device_takeover_leaves_the_previous_users_other_devices_alone(): void
    {
        $a = $this->student();
        $b = $this->student();

        $this->register($a, 'token-a-x', self::DEVICE_X);
        $this->register($a, 'token-a-y', self::DEVICE_Y);
        $this->assertSame(['token-a-x', 'token-a-y'], $this->deliveryTargets($a));

        $this->register($b, 'token-b-x', self::DEVICE_X);

        // A keeps Device Y, loses only Device X.
        $this->assertSame(['token-a-y'], $this->deliveryTargets($a));
    }

    public function test_registration_restores_a_soft_deleted_token_under_the_current_owner(): void
    {
        $a = $this->student();
        $b = $this->student();

        $this->register($a, 'shared-token', self::DEVICE_X);
        Sanctum::actingAs($a);
        $this->deleteJson($this->registerUrl, ['token' => 'shared-token'])->assertOk();
        $this->assertSoftDeleted('fcm_tokens', ['token' => 'shared-token']);

        $this->register($b, 'shared-token', self::DEVICE_X);

        $row = FcmToken::withTrashed()->where('token', 'shared-token')->sole();
        $this->assertNull($row->deleted_at);
        $this->assertTrue($row->is_active);
        $this->assertSame($b->id, $row->tokenable_id);

        $this->assertSame(['shared-token'], $this->deliveryTargets($b));
        $this->assertSame([], $this->deliveryTargets($a));
    }

    public function test_token_hash_is_unique_and_derived_from_the_raw_token(): void
    {
        $a = $this->student();
        $this->register($a, 'hashed-token', self::DEVICE_X);

        $row = FcmToken::sole();
        $this->assertSame(hash('sha256', 'hashed-token'), $row->token_hash);
        $this->assertSame('hashed-token', $row->token);

        $this->expectException(QueryException::class);
        DB::table('fcm_tokens')->insert([
            'tokenable_type' => 'user',
            'tokenable_id' => $a->id,
            'token' => 'hashed-token',
            'token_hash' => hash('sha256', 'hashed-token'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_logout_with_device_id_deactivates_only_that_device(): void
    {
        $a = $this->student();
        $this->register($a, 'token-x', self::DEVICE_X);
        $this->register($a, 'token-y', self::DEVICE_Y);

        Sanctum::actingAs($a);
        $this->postJson($this->logoutUrl, ['device_id' => self::DEVICE_X])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(['token-y'], $this->deliveryTargets($a));
    }

    public function test_logout_with_fcm_token_deactivates_that_registration(): void
    {
        $a = $this->student();
        $this->register($a, 'token-x', self::DEVICE_X);
        $this->register($a, 'token-y', self::DEVICE_Y);

        Sanctum::actingAs($a);
        $this->postJson($this->logoutUrl, ['fcm_token' => 'token-x'])->assertOk();

        $this->assertSame(['token-y'], $this->deliveryTargets($a));
    }

    public function test_logout_without_fcm_information_stays_backward_compatible(): void
    {
        $a = $this->student();
        $this->register($a, 'token-x', self::DEVICE_X);

        Sanctum::actingAs($a);
        $this->postJson($this->logoutUrl)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(['token-x'], $this->deliveryTargets($a));
    }

    public function test_logout_cannot_deactivate_another_users_device(): void
    {
        $a = $this->student();
        $b = $this->student();

        $this->register($a, 'token-a', self::DEVICE_X);
        $this->register($b, 'token-b', self::DEVICE_Y);

        Sanctum::actingAs($b);
        $this->postJson($this->logoutUrl, [
            'device_id' => self::DEVICE_X,
            'fcm_token' => 'token-a',
        ])->assertOk();

        $this->assertSame(['token-a'], $this->deliveryTargets($a));
    }
}
