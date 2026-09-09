<?php

namespace Tests\Feature\Database\Seeders;

use App\Enums\AdminType;
use App\Models\Admin;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_seeder_uses_email_from_config(): void
    {
        Config::set('auth_features.super_admin.email', 'configured-admin@example.com');
        Config::set('auth_features.super_admin.password', 'Password123!');

        $this->seed(SuperAdminSeeder::class);

        $this->assertDatabaseHas('admins', [
            'email' => 'configured-admin@example.com',
        ]);
    }

    public function test_seeder_falls_back_to_default_email_when_not_configured(): void
    {
        // No SUPER_ADMIN_EMAIL env override exists in the test environment,
        // so config/auth_features.php's own default applies.
        $this->assertSame('admin@pulvent.com', config('auth_features.super_admin.email'));

        $this->seed(SuperAdminSeeder::class);

        $this->assertDatabaseHas('admins', [
            'email' => 'admin@pulvent.com',
        ]);
    }

    public function test_seeder_creates_admin_with_super_admin_type(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $admin = Admin::query()->where('email', config('auth_features.super_admin.email'))->firstOrFail();

        $this->assertSame(AdminType::SuperAdmin, $admin->type);
        $this->assertTrue($admin->hasRole('super_admin'));
    }

    public function test_no_email_logic_references_legacy_root_email(): void
    {
        $contents = file_get_contents(base_path('database/seeders/SuperAdminSeeder.php'));

        $this->assertStringNotContainsString('legacy-root@example.test', $contents);
    }
}
