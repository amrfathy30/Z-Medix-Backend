<?php

namespace Tests\Feature\Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Models\Admin;
use App\Models\Blog;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The permission seeders run on live environments, so re-running them must be
 * idempotent and must never touch data an operator owns: custom roles, custom
 * permissions, admin credentials or content.
 */
class PermissionSeedingSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_reseeding_does_not_change_permission_or_role_counts(): void
    {
        $permissions = Permission::query()->where('guard_name', 'admin')->count();
        $roles = Role::query()->where('guard_name', 'admin')->count();

        $this->seed(RolesPermissionsSeeder::class);

        $this->assertSame($permissions, Permission::query()->where('guard_name', 'admin')->count());
        $this->assertSame($roles, Role::query()->where('guard_name', 'admin')->count());
    }

    public function test_reseeding_keeps_custom_roles_and_their_permissions(): void
    {
        $role = Role::create([
            'name' => 'operator_defined_role',
            'guard_name' => 'admin',
            'display_name_ar' => 'دور مخصص',
            'display_name_en' => 'Operator Defined Role',
        ]);
        $role->syncPermissions(['subjects.view', 'report_cases.view']);

        $this->seed(RolesPermissionsSeeder::class);

        $role->refresh();

        $this->assertDatabaseHas('roles', ['name' => 'operator_defined_role']);
        $this->assertTrue($role->hasPermissionTo('subjects.view'));
        $this->assertTrue($role->hasPermissionTo('report_cases.view'));
    }

    public function test_reseeding_keeps_a_permission_the_seeder_does_not_define(): void
    {
        Permission::create(['name' => 'future_module.view', 'guard_name' => 'admin']);

        $this->seed(RolesPermissionsSeeder::class);

        $this->assertDatabaseHas('permissions', ['name' => 'future_module.view', 'guard_name' => 'admin']);
    }

    public function test_reseeding_does_not_touch_admins_or_content(): void
    {
        $admin = Admin::factory()->create([
            'name' => 'Operator Owned',
            'status' => AccountStatus::Suspended,
            'type' => AdminType::Admin,
            'password' => Hash::make('operator-password'),
        ]);
        $originalPassword = $admin->password;
        $blog = Blog::factory()->create();

        $this->seed(RolesPermissionsSeeder::class);

        $admin->refresh();

        $this->assertSame('Operator Owned', $admin->name);
        $this->assertSame($originalPassword, $admin->password);
        $this->assertSame(AccountStatus::Suspended, $admin->status);
        $this->assertDatabaseHas('blogs', ['id' => $blog->id]);
    }

    public function test_the_super_admin_seeder_never_resets_an_existing_password(): void
    {
        Config::set('auth_features.super_admin.email', 'root@example.com');
        Config::set('auth_features.super_admin.password', 'InitialPassword123!');

        $this->seed(SuperAdminSeeder::class);

        $superAdmin = Admin::query()->where('email', 'root@example.com')->sole();

        // The operator rotates the password after the first deploy.
        $superAdmin->forceFill([
            'name' => 'Renamed By Operator',
            'password' => Hash::make('RotatedByOperator456!'),
        ])->save();
        $rotatedPassword = $superAdmin->refresh()->password;

        $this->seed(SuperAdminSeeder::class);

        $superAdmin->refresh();

        $this->assertSame($rotatedPassword, $superAdmin->password);
        $this->assertSame('Renamed By Operator', $superAdmin->name);
        $this->assertSame(AdminType::SuperAdmin, $superAdmin->type);
        $this->assertSame(AccountStatus::Active, $superAdmin->status);
        $this->assertTrue($superAdmin->hasRole('super_admin'));
        $this->assertSame(1, Admin::query()->where('email', 'root@example.com')->count());
    }
}
