<?php

namespace Tests\Feature\Cms;

use App\Enums\SettingValueType;
use App\Filament\Pages\SettingsPage;
use App\Models\Admin;
use App\Models\Setting;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function superAdmin(): Admin
    {
        $admin = Admin::factory()->superAdmin()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_no_op_save_does_not_touch_or_audit_setting(): void
    {
        $setting = Setting::factory()->create([
            'key' => 'site_name', 'value' => 'Original', 'value_type' => SettingValueType::String,
            'group' => 'general', 'is_public' => true,
        ]);
        $updatedAt = $setting->updated_at;
        $this->travel(5)->minutes();

        Livewire::actingAs($this->superAdmin(), 'admin')->test(SettingsPage::class)->call('save');

        $this->assertEquals($updatedAt->timestamp, $setting->fresh()->updated_at->timestamp);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_real_change_is_saved_and_audited_without_project_specific_data(): void
    {
        $setting = Setting::factory()->create([
            'key' => 'site_name', 'value' => 'Original', 'value_type' => SettingValueType::String,
            'group' => 'general', 'is_public' => true,
        ]);

        Livewire::actingAs($this->superAdmin(), 'admin')->test(SettingsPage::class)
            ->set("data.setting_{$setting->id}", 'Updated')->call('save');

        $activity = Activity::query()->firstOrFail();
        $this->assertSame('Updated', $setting->fresh()->value);
        $this->assertSame('site_name', $activity->properties['key']);
        $this->assertSame('Original', $activity->properties['old_value']);
        $this->assertSame('Updated', $activity->properties['new_value']);
    }

    public function test_image_setting_renders_on_filament_public_disk(): void
    {
        $setting = Setting::factory()->create([
            'key' => 'site_logo', 'value' => null, 'value_type' => SettingValueType::Image,
            'group' => 'general', 'is_public' => true,
        ]);

        Livewire::actingAs($this->superAdmin(), 'admin')->test(SettingsPage::class)
            ->assertFormFieldExists("setting_{$setting->id}", 'form', fn ($field): bool => $field->getDiskName() === 'filament_public');
    }
}
