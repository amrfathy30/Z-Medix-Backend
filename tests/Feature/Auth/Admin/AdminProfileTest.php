<?php

namespace Tests\Feature\Auth\Admin;

use App\Contracts\PhoneVerificationProviderInterface;
use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\Admin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prevent Twilio from being instantiated — phone tests only call operations that
        // don't touch the verification provider (add, delete, setPrimary, updatePhone).
        $this->mock(PhoneVerificationProviderInterface::class);
    }

    private function admin(array $attributes = []): Admin
    {
        return Admin::factory()->create(array_merge([
            'status' => AccountStatus::Active,
            'type' => AdminType::Admin,
            'password' => Hash::make('password'),
        ], $attributes));
    }

    // ─── Page Access ──────────────────────────────────────────────────────────

    public function test_authenticated_admin_can_render_profile_page(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->assertOk();
    }

    public function test_unauthenticated_user_cannot_access_profile_page(): void
    {
        $this->get(route('filament.admin.auth.profile'))
            ->assertRedirect();
    }

    // ─── Basic Info Update ────────────────────────────────────────────────────

    public function test_admin_can_update_first_and_last_name(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => $admin->email,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    }

    public function test_admin_display_name_uses_first_and_last_name_when_available(): void
    {
        $admin = $this->admin([
            'name' => 'Old Name',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $this->assertSame('Jane Smith', $admin->display_name);
    }

    public function test_admin_display_name_falls_back_to_name_when_first_last_not_set(): void
    {
        $admin = $this->admin([
            'name' => 'Fallback Name',
            'first_name' => null,
            'last_name' => null,
        ]);

        $this->assertSame('Fallback Name', $admin->display_name);
    }

    public function test_admin_can_update_job_title(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'email' => $admin->email,
                'job_title' => 'Senior Counsel',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'job_title' => 'Senior Counsel',
        ]);
    }

    public function test_department_is_not_shown_in_self_profile_form(): void
    {
        $admin = $this->admin(['department' => 'Legal']);

        // Saving the profile should not affect the department column
        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'email' => $admin->email,
                'first_name' => 'Jane',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // department column must remain unchanged since it is not part of the form
        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'department' => 'Legal',
        ]);
    }

    // ─── Email Update ─────────────────────────────────────────────────────────

    public function test_admin_email_update_resets_email_verified_at(): void
    {
        $admin = $this->admin(['email_verified_at' => now()]);

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'email' => 'newemail@example.com',
                'emailCurrentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'email' => 'newemail@example.com',
            'email_verified_at' => null,
        ]);
    }

    public function test_admin_email_update_requires_current_password(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'email' => 'newemail@example.com',
                'emailCurrentPassword' => 'wrong-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['emailCurrentPassword']);
    }

    // ─── Root Admin Email Protection ──────────────────────────────────────────
    //
    // Protection is based on AdminType::SuperAdmin, not on any specific
    // email address — every super admin is protected, and a non-super-admin
    // is never protected no matter what email it has.

    public function test_super_admin_email_cannot_be_changed(): void
    {
        $superAdmin = $this->admin(['email' => 'super@example.com', 'type' => AdminType::SuperAdmin]);

        Livewire::actingAs($superAdmin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'first_name' => 'Root',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('admins', [
            'id' => $superAdmin->id,
            'email' => 'super@example.com',
        ]);
    }

    public function test_super_admin_email_cannot_be_changed_even_when_submitted(): void
    {
        $superAdmin = $this->admin(['email' => 'super@example.com', 'type' => AdminType::SuperAdmin]);

        Livewire::actingAs($superAdmin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'email' => 'attempted-change@example.com',
                'emailCurrentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('admins', [
            'id' => $superAdmin->id,
            'email' => 'super@example.com',
        ]);
    }

    public function test_non_super_admin_with_legacy_root_email_is_not_protected(): void
    {
        $admin = $this->admin(['email' => 'legacy-root@example.test', 'type' => AdminType::Admin]);

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'email' => 'newemail@example.com',
                'emailCurrentPassword' => 'password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'email' => 'newemail@example.com',
        ]);
    }

    public function test_no_legacy_root_email_logic_remains(): void
    {
        $contents = file_get_contents(app_path('Filament/Pages/Auth/EditProfile.php'));

        $this->assertStringNotContainsString('legacy-root@example.test', $contents);
    }

    // ─── Password Change ──────────────────────────────────────────────────────

    public function test_admin_can_change_password_with_correct_current_password(): void
    {
        $admin = $this->admin(['password' => Hash::make('old-password')]);

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'email' => $admin->email,
                'currentPassword' => 'old-password',
                'password' => 'new-password-123',
                'passwordConfirmation' => 'new-password-123',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('new-password-123', $admin->fresh()->password));
    }

    public function test_admin_cannot_change_password_with_wrong_current_password(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'email' => $admin->email,
                'currentPassword' => 'wrong-password',
                'password' => 'new-password-123',
                'passwordConfirmation' => 'new-password-123',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);
    }

    public function test_admin_password_change_requires_confirmation(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'email' => $admin->email,
                'currentPassword' => 'password',
                'password' => 'new-password-123',
                'passwordConfirmation' => 'different-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);
    }

    // ─── Phone Numbers ────────────────────────────────────────────────────────

    public function test_admin_phone_numbers_are_listed_in_profile(): void
    {
        $admin = $this->admin();
        $admin->phoneNumbers()->create([
            'owner_type' => $admin->getMorphClass(),
            'owner_id' => $admin->id,
            'country_iso2' => 'EG',
            'country_code' => '+20',
            'national_number' => '1234567890',
            'e164_number' => '+201234567890',
            'is_primary' => true,
            'status' => 'pending',
        ]);

        $component = Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class);

        // Phone numbers are loaded into the form's data property
        $phoneNumbers = $component->get('data.phone_numbers');
        $this->assertIsArray($phoneNumbers);
        $this->assertCount(1, $phoneNumbers);
        $first = reset($phoneNumbers);
        $this->assertSame('+201234567890', $first['phone']);
    }

    public function test_admin_can_delete_phone_number_via_profile(): void
    {
        $admin = $this->admin();
        $admin->phoneNumbers()->create([
            'owner_type' => $admin->getMorphClass(),
            'owner_id' => $admin->id,
            'country_iso2' => 'EG',
            'country_code' => '+20',
            'national_number' => '1234567890',
            'e164_number' => '+201234567890',
            'is_primary' => true,
            'status' => 'pending',
        ]);

        // Submit with empty phone_numbers to delete
        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'email' => $admin->email,
                'phone_numbers' => [],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseMissing('phone_numbers', [
            'owner_type' => $admin->getMorphClass(),
            'owner_id' => $admin->id,
            'deleted_at' => null,
        ]);
    }

    // ─── Architecture Checks ─────────────────────────────────────────────────

    public function test_no_admin_resource_exists(): void
    {
        $this->assertFalse(
            class_exists('App\\Filament\\Resources\\AdminResource'),
            'AdminResource should not be created.'
        );
    }

    public function test_no_role_resource_exists(): void
    {
        $this->assertFalse(
            class_exists('App\\Filament\\Resources\\RoleResource'),
            'RoleResource should not be created.'
        );
    }

    // ─── Model & Schema ───────────────────────────────────────────────────────

    public function test_admin_model_casts_email_verified_at_as_datetime(): void
    {
        $admin = $this->admin(['email_verified_at' => now()]);

        $this->assertInstanceOf(Carbon::class, $admin->email_verified_at);
    }

    public function test_admins_table_has_nullable_email_verified_at_column(): void
    {
        $admin = $this->admin();

        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'email_verified_at' => null,
        ]);
    }

    public function test_admins_table_has_nullable_first_last_name_columns(): void
    {
        $admin = $this->admin(['first_name' => null, 'last_name' => null]);

        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'first_name' => null,
            'last_name' => null,
        ]);
    }

    // ─── Safety Guards ────────────────────────────────────────────────────────

    public function test_no_registration_routes_exist(): void
    {
        $this->postJson('/api/public/auth/register', [])->assertStatus(404);
    }

    public function test_no_phone_otp_login_routes_exist(): void
    {
        $this->postJson('/api/public/auth/phone/login', [])->assertStatus(404);
    }

    public function test_no_public_user_phone_api_exists(): void
    {
        $this->postJson('/api/public/phones', [])->assertStatus(404);
    }
}
