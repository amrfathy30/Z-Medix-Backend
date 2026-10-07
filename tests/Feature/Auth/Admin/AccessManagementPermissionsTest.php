<?php

namespace Tests\Feature\Auth\Admin;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Filament\Pages\AccessManagementPage;
use App\Models\Admin;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Access Management exposes the admins.* and roles.* permission families. The
 * page is reachable with admins.view, and each action is gated by the exact
 * permission that names it, so what a role can do on screen matches what the
 * seeder granted it.
 */
class AccessManagementPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesPermissionsSeeder::class);
    }

    private function adminWithRole(string $roleName, AdminType $type = AdminType::Admin): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => $type,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole($roleName);

        return $admin;
    }

    private function adminWithoutPermissions(): Admin
    {
        $role = Role::firstOrCreate(
            ['name' => 'no_access_role', 'guard_name' => 'admin'],
            ['display_name_ar' => 'بدون صلاحيات', 'display_name_en' => 'No Access'],
        );
        $role->syncPermissions([]);

        return $this->adminWithRole('no_access_role');
    }

    // ─── Page access ────────────────────────────────────────────────────────

    public function test_an_admin_without_admins_view_cannot_reach_access_management(): void
    {
        $this->actingAs($this->adminWithoutPermissions(), 'admin');

        $this->assertFalse(AccessManagementPage::canAccess());
    }

    public function test_the_admin_and_super_admin_roles_can_reach_access_management(): void
    {
        $this->actingAs($this->adminWithRole('admin'), 'admin');
        $this->assertTrue(AccessManagementPage::canAccess());

        $this->actingAs($this->adminWithRole('super_admin', AdminType::SuperAdmin), 'admin');
        $this->assertTrue(AccessManagementPage::canAccess());
    }

    // ─── Role actions ───────────────────────────────────────────────────────

    public function test_the_admin_role_cannot_create_update_or_delete_roles(): void
    {
        // The admin role holds roles.view only, so the role cards are readable
        // but none of their write actions are offered.
        $role = Role::query()->where('name', 'content_manager')->sole();

        Livewire::actingAs($this->adminWithRole('admin'), 'admin')
            ->test(AccessManagementPage::class)
            ->assertOk()
            ->assertActionHidden('createRole')
            ->assertActionHidden('editRole', ['roleId' => $role->id])
            ->assertActionHidden('deleteRole', ['roleId' => $role->id]);
    }

    public function test_a_super_admin_can_create_update_and_delete_roles(): void
    {
        $role = Role::query()->where('name', 'content_manager')->sole();

        Livewire::actingAs($this->adminWithRole('super_admin', AdminType::SuperAdmin), 'admin')
            ->test(AccessManagementPage::class)
            ->assertOk()
            ->assertActionVisible('createRole')
            ->assertActionVisible('editRole', ['roleId' => $role->id])
            ->assertActionVisible('deleteRole', ['roleId' => $role->id]);
    }

    public function test_an_unauthorized_admin_cannot_mount_the_create_role_action(): void
    {
        $rolesBefore = Role::query()->count();

        // Filament refuses to mount an action it reports as hidden, so an
        // unauthorized admin has no path to the role form at all.
        $page = Livewire::actingAs($this->adminWithRole('admin'), 'admin')
            ->test(AccessManagementPage::class)
            ->assertActionHidden('createRole');

        $page->call('mountAction', 'createRole');

        $this->assertSame($rolesBefore, Role::query()->count());
    }

    public function test_a_super_admin_still_creates_a_role_through_the_gated_action(): void
    {
        Livewire::actingAs($this->adminWithRole('super_admin', AdminType::SuperAdmin), 'admin')
            ->test(AccessManagementPage::class)
            ->callAction('createRole', [
                'display_name_ar' => 'دور جديد',
                'display_name_en' => 'Reviewer',
                'permissions' => ['report_cases.view'],
            ])
            ->assertHasNoActionErrors();

        $role = Role::query()->where('display_name_en', 'Reviewer')->sole();

        $this->assertTrue($role->hasPermissionTo('report_cases.view'));
    }

    public function test_the_roles_section_is_hidden_from_an_admin_without_roles_view(): void
    {
        $role = Role::firstOrCreate(
            ['name' => 'employees_only_role', 'guard_name' => 'admin'],
            ['display_name_ar' => 'الموظفون فقط', 'display_name_en' => 'Employees Only'],
        );
        $role->syncPermissions(['admins.view']);

        Livewire::actingAs($this->adminWithRole('employees_only_role'), 'admin')
            ->test(AccessManagementPage::class)
            ->assertOk()
            ->assertDontSee(__('admin.access_management.roles_section'))
            ->assertSee(__('admin.access_management.admins_section'));
    }

    // ─── Admin actions ──────────────────────────────────────────────────────

    public function test_the_admin_role_can_create_and_edit_but_not_delete_employees(): void
    {
        $target = $this->adminWithRole('content_manager');

        Livewire::actingAs($this->adminWithRole('admin'), 'admin')
            ->test(AccessManagementPage::class)
            ->assertOk()
            ->assertActionVisible('createAdmin')
            ->assertTableActionVisible('editAdmin', record: $target)
            ->assertTableActionVisible('toggleStatus', record: $target)
            ->assertTableActionHidden('deleteAdmin', record: $target);
    }

    public function test_a_super_admin_can_delete_a_non_super_admin_employee(): void
    {
        $target = $this->adminWithRole('content_manager');

        Livewire::actingAs($this->adminWithRole('super_admin', AdminType::SuperAdmin), 'admin')
            ->test(AccessManagementPage::class)
            ->assertTableActionVisible('deleteAdmin', record: $target);
    }

    // ─── Super admin escalation ─────────────────────────────────────────────

    /**
     * An employee-manager role: it may create and update admins, but it is not
     * itself a super admin. Built from the seeded admins.* permissions.
     */
    private function employeeManager(): Admin
    {
        $role = Role::firstOrCreate(
            ['name' => 'employee_manager_role', 'guard_name' => 'admin'],
            ['display_name_ar' => 'مدير الموظفين', 'display_name_en' => 'Employee Manager'],
        );
        $role->syncPermissions(['admins.view', 'admins.create', 'admins.update', 'roles.view']);

        return $this->adminWithRole('employee_manager_role');
    }

    public function test_a_super_admin_can_create_a_super_admin(): void
    {
        Livewire::actingAs($this->adminWithRole('super_admin', AdminType::SuperAdmin), 'admin')
            ->test(AccessManagementPage::class)
            ->callAction('createAdmin', [
                'name' => 'Second Root',
                'email' => 'second-root@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'type' => AdminType::SuperAdmin->value,
                'status' => AccountStatus::Active->value,
                'role' => 'super_admin',
            ])
            ->assertHasNoActionErrors();

        $created = Admin::query()->where('email', 'second-root@example.com')->sole();

        $this->assertSame(AdminType::SuperAdmin, $created->type);
        $this->assertTrue($created->hasRole('super_admin'));
    }

    public function test_a_normal_admin_cannot_create_an_admin_with_the_super_admin_role(): void
    {
        // A crafted payload: the super_admin option is not even offered to this
        // actor, so this bypasses the form options entirely.
        Livewire::actingAs($this->employeeManager(), 'admin')
            ->test(AccessManagementPage::class)
            ->callAction('createAdmin', [
                'name' => 'Smuggled Root',
                'email' => 'smuggled-root@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'type' => AdminType::Admin->value,
                'status' => AccountStatus::Active->value,
                'role' => 'super_admin',
            ]);

        $this->assertDatabaseMissing('admins', ['email' => 'smuggled-root@example.com']);
    }

    public function test_a_normal_admin_cannot_create_an_admin_with_the_super_admin_type(): void
    {
        Livewire::actingAs($this->employeeManager(), 'admin')
            ->test(AccessManagementPage::class)
            ->callAction('createAdmin', [
                'name' => 'Smuggled Type',
                'email' => 'smuggled-type@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'type' => AdminType::SuperAdmin->value,
                'status' => AccountStatus::Active->value,
                'role' => 'content_manager',
            ]);

        $this->assertDatabaseMissing('admins', ['email' => 'smuggled-type@example.com']);
    }

    public function test_a_normal_admin_cannot_promote_an_existing_admin_to_super_admin(): void
    {
        $target = $this->adminWithRole('content_manager');

        Livewire::actingAs($this->employeeManager(), 'admin')
            ->test(AccessManagementPage::class)
            ->callTableAction('editAdmin', record: $target, data: [
                'name' => $target->name,
                'email' => $target->email,
                'type' => AdminType::SuperAdmin->value,
                'status' => AccountStatus::Active->value,
                'role' => 'super_admin',
            ]);

        $target->refresh();

        $this->assertSame(AdminType::Admin, $target->type);
        $this->assertFalse($target->hasRole('super_admin'));
        $this->assertTrue($target->hasRole('content_manager'));
    }

    public function test_the_super_admin_role_and_type_are_not_valid_choices_for_a_normal_admin(): void
    {
        // The options a normal admin is offered are also what Filament validates
        // the submission against, so a value outside them is rejected by the
        // server rather than merely hidden in the UI.
        Livewire::actingAs($this->employeeManager(), 'admin')
            ->test(AccessManagementPage::class)
            ->callAction('createAdmin', [
                'name' => 'Rejected By Validation',
                'email' => 'rejected@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'type' => AdminType::SuperAdmin->value,
                'status' => AccountStatus::Active->value,
                'role' => 'super_admin',
            ])
            ->assertHasActionErrors(['type', 'role']);

        $this->assertDatabaseMissing('admins', ['email' => 'rejected@example.com']);
    }

    public function test_a_crafted_root_admin_flag_cannot_smuggle_a_promotion_past_validation(): void
    {
        $target = $this->adminWithRole('content_manager');

        // _is_root_admin drives which options the edit form offers, so a crafted
        // payload can widen them past validation. The server-side check reads
        // the record from the database instead of trusting that flag.
        Livewire::actingAs($this->employeeManager(), 'admin')
            ->test(AccessManagementPage::class)
            ->callTableAction('editAdmin', record: $target, data: [
                '_is_root_admin' => true,
                'name' => $target->name,
                'email' => $target->email,
                'type' => AdminType::SuperAdmin->value,
                'status' => AccountStatus::Active->value,
                'role' => 'super_admin',
            ]);

        $target->refresh();

        $this->assertSame(AdminType::Admin, $target->type);
        $this->assertFalse($target->hasRole('super_admin'));
    }

    public function test_a_normal_admin_can_still_create_and_update_an_ordinary_admin(): void
    {
        $actor = $this->employeeManager();

        Livewire::actingAs($actor, 'admin')
            ->test(AccessManagementPage::class)
            ->callAction('createAdmin', [
                'name' => 'Ordinary Employee',
                'email' => 'ordinary@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'type' => AdminType::Admin->value,
                'status' => AccountStatus::Active->value,
                'role' => 'content_manager',
            ])
            ->assertHasNoActionErrors();

        $created = Admin::query()->where('email', 'ordinary@example.com')->sole();

        $this->assertSame(AdminType::Admin, $created->type);
        $this->assertTrue($created->hasRole('content_manager'));

        Livewire::actingAs($actor, 'admin')
            ->test(AccessManagementPage::class)
            ->callTableAction('editAdmin', record: $created, data: [
                'name' => 'Ordinary Renamed',
                'email' => 'ordinary@example.com',
                'type' => AdminType::Admin->value,
                'status' => AccountStatus::Suspended->value,
                'role' => 'admin',
            ])
            ->assertHasNoTableActionErrors();

        $created->refresh();

        $this->assertSame('Ordinary Renamed', $created->name);
        $this->assertSame(AccountStatus::Suspended, $created->status);
        $this->assertTrue($created->hasRole('admin'));
    }

    // ─── Super admin records are read-only to everyone else ─────────────────

    public function test_a_normal_admin_is_offered_no_write_action_on_a_super_admin(): void
    {
        $root = $this->adminWithRole('super_admin', AdminType::SuperAdmin);

        Livewire::actingAs($this->employeeManager(), 'admin')
            ->test(AccessManagementPage::class)
            ->assertOk()
            ->assertTableActionHidden('editAdmin', record: $root)
            ->assertTableActionHidden('toggleStatus', record: $root)
            ->assertTableActionHidden('deleteAdmin', record: $root);
    }

    public function test_a_normal_admin_cannot_suspend_a_super_admin(): void
    {
        $root = $this->adminWithRole('super_admin', AdminType::SuperAdmin);

        // mountAction is the raw Livewire entry point a crafted request would
        // hit; unlike callTableAction it does not assert visibility first, so
        // this exercises the server-side refusal rather than the hidden button.
        Livewire::actingAs($this->employeeManager(), 'admin')
            ->test(AccessManagementPage::class)
            ->mountAction(TestAction::make('toggleStatus')->table($root))
            ->callMountedAction();

        $this->assertSame(AccountStatus::Active, $root->refresh()->status);
    }

    public function test_a_normal_admin_cannot_rename_a_super_admin(): void
    {
        $root = $this->adminWithRole('super_admin', AdminType::SuperAdmin);
        $originalName = $root->name;

        Livewire::actingAs($this->employeeManager(), 'admin')
            ->test(AccessManagementPage::class)
            ->mountAction(TestAction::make('editAdmin')->table($root))
            ->setActionData([
                'name' => 'Renamed By Someone Else',
                'email' => $root->email,
                'type' => AdminType::SuperAdmin->value,
                'status' => AccountStatus::Active->value,
                'role' => 'super_admin',
            ])
            ->callMountedAction();

        $this->assertSame($originalName, $root->refresh()->name);
    }

    public function test_a_crafted_payload_cannot_change_any_super_admin_field(): void
    {
        $root = $this->adminWithRole('super_admin', AdminType::SuperAdmin);
        $original = $root->only(['name', 'email', 'password']);

        Livewire::actingAs($this->employeeManager(), 'admin')
            ->test(AccessManagementPage::class)
            ->mountAction(TestAction::make('editAdmin')->table($root))
            ->setActionData([
                '_is_root_admin' => false,
                'name' => 'Hijacked',
                'email' => 'hijacked@example.com',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
                'type' => AdminType::Admin->value,
                'status' => AccountStatus::Blocked->value,
                'role' => 'content_manager',
            ])
            ->callMountedAction();

        $root->refresh();

        $this->assertSame($original['name'], $root->name);
        $this->assertSame($original['email'], $root->email);
        $this->assertSame($original['password'], $root->password);
        $this->assertSame(AdminType::SuperAdmin, $root->type);
        $this->assertSame(AccountStatus::Active, $root->status);
        $this->assertTrue($root->hasRole('super_admin'));
        $this->assertFalse($root->hasRole('content_manager'));
    }

    public function test_a_super_admin_can_still_edit_another_super_admin(): void
    {
        $actor = $this->adminWithRole('super_admin', AdminType::SuperAdmin);
        $other = $this->adminWithRole('super_admin', AdminType::SuperAdmin);

        Livewire::actingAs($actor, 'admin')
            ->test(AccessManagementPage::class)
            ->assertTableActionVisible('editAdmin', record: $other)
            ->callTableAction('editAdmin', record: $other, data: [
                'name' => 'Renamed By A Peer',
                'email' => $other->email,
                'type' => AdminType::SuperAdmin->value,
                'status' => AccountStatus::Active->value,
                'role' => 'super_admin',
            ])
            ->assertHasNoTableActionErrors();

        $other->refresh();

        $this->assertSame('Renamed By A Peer', $other->name);
        $this->assertSame(AdminType::SuperAdmin, $other->type);
        $this->assertTrue($other->hasRole('super_admin'));
    }

    public function test_an_admin_without_admins_update_cannot_suspend_an_employee(): void
    {
        $target = $this->adminWithRole('content_manager');

        // content_manager holds admins.view only, so the employee table is
        // readable but suspend/edit are not offered.
        Livewire::actingAs($this->adminWithRole('content_manager'), 'admin')
            ->test(AccessManagementPage::class)
            ->assertOk()
            ->assertActionHidden('createAdmin')
            ->assertTableActionHidden('editAdmin', record: $target)
            ->assertTableActionHidden('toggleStatus', record: $target)
            ->assertTableActionHidden('deleteAdmin', record: $target);

        $this->assertSame(AccountStatus::Active, $target->refresh()->status);
    }
}
