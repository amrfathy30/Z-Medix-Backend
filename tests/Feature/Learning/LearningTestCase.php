<?php

namespace Tests\Feature\Learning;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Models\Admin;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Shared setup for the Subject content module: seeded roles/permissions, a
 * super admin to act as, and faked media disks.
 */
abstract class LearningTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('filament_public');

        $this->seed(RolesPermissionsSeeder::class);
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
}
