<?php

namespace Tests\Feature\Auth\Admin;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Filament\Pages\AccessManagementPage;
use App\Models\Admin;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Root admin protection in AccessManagementPage relies on
 * AdminType::SuperAdmin, not on any specific email address — every super
 * admin is protected, and a non-super-admin is never protected no matter
 * what email it has.
 */
class AccessManagementPageProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function admin(array $attributes = []): Admin
    {
        $admin = Admin::factory()->create(array_merge([
            'status' => AccountStatus::Active,
            'type' => AdminType::Admin,
            'password' => Hash::make('password'),
        ], $attributes));
        $admin->assignRole('admin');

        return $admin;
    }

    private function actingSuperAdmin(): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::SuperAdmin,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_delete_action_is_hidden_for_any_super_admin(): void
    {
        $actor = $this->actingSuperAdmin();
        $protected = $this->admin(['email' => 'super@example.com', 'type' => AdminType::SuperAdmin]);
        $protected->assignRole('super_admin');

        Livewire::actingAs($actor, 'admin')
            ->test(AccessManagementPage::class)
            ->assertTableActionHidden('deleteAdmin', record: $protected);
    }

    public function test_delete_action_is_visible_for_non_super_admin_with_legacy_root_email(): void
    {
        $actor = $this->actingSuperAdmin();
        $legacyEmailAdmin = $this->admin(['email' => 'legacy-root@example.test', 'type' => AdminType::Admin]);

        Livewire::actingAs($actor, 'admin')
            ->test(AccessManagementPage::class)
            ->assertTableActionVisible('deleteAdmin', record: $legacyEmailAdmin);
    }

    public function test_super_admin_email_cannot_be_changed_through_edit_action(): void
    {
        $actor = $this->actingSuperAdmin();
        $protected = $this->admin(['email' => 'super@example.com', 'type' => AdminType::SuperAdmin]);
        $protected->assignRole('super_admin');

        Livewire::actingAs($actor, 'admin')
            ->test(AccessManagementPage::class)
            ->callTableAction('editAdmin', record: $protected, data: [
                'name' => 'Renamed',
                'email' => 'attempted-change@example.com',
                'status' => AccountStatus::Active->value,
            ]);

        $this->assertDatabaseHas('admins', [
            'id' => $protected->id,
            'email' => 'super@example.com',
        ]);
    }

    public function test_no_legacy_root_email_logic_remains(): void
    {
        $contents = file_get_contents(app_path('Filament/Pages/AccessManagementPage.php'));

        $this->assertStringNotContainsString('legacy-root@example.test', $contents);
    }
}
