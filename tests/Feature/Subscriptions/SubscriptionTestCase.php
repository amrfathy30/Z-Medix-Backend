<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Models\Admin;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SubscribesStudents;
use Tests\TestCase;

/**
 * Shared setup for the plans and subscriptions suites: seeded roles, faked disks
 * (the payment proof must land on the private one), and admin/student helpers.
 * Students here start with NO subscription, unlike the study and Fellowship
 * suites, because the gate is what these tests are about.
 */
abstract class SubscriptionTestCase extends TestCase
{
    use RefreshDatabase, SubscribesStudents;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('filament_public');

        $this->seed(RolesPermissionsSeeder::class);
    }

    /** An active student with no subscription at all. */
    protected function unsubscribedStudent(): User
    {
        return User::factory()->create(['status' => AccountStatus::Active]);
    }

    protected function actingAsStudent(User $student): User
    {
        Sanctum::actingAs($student);

        return $student;
    }

    protected function superAdmin(): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::SuperAdmin,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    protected function adminWithRole(string $roleName): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::Admin,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole($roleName);

        return $admin;
    }

    /**
     * An admin holding exactly these permissions and no role, so a test can pin
     * down what one permission alone allows.
     *
     * @param  list<string>  $permissions
     */
    protected function adminWithPermissions(array $permissions): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::Admin,
            'password' => Hash::make('password'),
        ]);

        $admin->givePermissionTo($permissions);

        return $admin->refresh();
    }
}
