<?php

namespace Tests\Feature\Cms;

use App\Enums\SettingValueType;
use App\Filament\Pages\SettingsPage;
use App\Models\Admin;
use App\Models\Setting;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Forms\Components\Textarea;
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

    /** @param  array<string, string>  $value */
    private function translatableSetting(string $key = 'footer_description_1', array $value = ['en' => 'Hello', 'ar' => 'مرحبا'], ?string $rawJson = null): Setting
    {
        return Setting::factory()->create([
            'key' => $key,
            'value' => $rawJson ?? json_encode($value, JSON_UNESCAPED_UNICODE),
            'value_type' => SettingValueType::Json,
            'group' => 'footer',
            'is_public' => true,
        ]);
    }

    public function test_translatable_setting_renders_one_input_per_locale_prefilled(): void
    {
        $setting = $this->translatableSetting();

        Livewire::actingAs($this->superAdmin(), 'admin')->test(SettingsPage::class)
            ->assertFormFieldExists("setting_{$setting->id}.en", 'form')
            ->assertFormFieldExists("setting_{$setting->id}.ar", 'form')
            ->assertSet("data.setting_{$setting->id}.en", 'Hello')
            ->assertSet("data.setting_{$setting->id}.ar", 'مرحبا');
    }

    public function test_description_settings_use_a_textarea_per_locale(): void
    {
        $setting = $this->translatableSetting('footer_description_2');

        Livewire::actingAs($this->superAdmin(), 'admin')->test(SettingsPage::class)
            ->assertFormFieldExists("setting_{$setting->id}.en", 'form', fn ($field): bool => $field instanceof Textarea);
    }

    public function test_changing_one_locale_saves_the_json_map_and_audits_it(): void
    {
        $setting = $this->translatableSetting();

        Livewire::actingAs($this->superAdmin(), 'admin')->test(SettingsPage::class)
            ->set("data.setting_{$setting->id}.en", 'Hi there')->call('save');

        $this->assertSame(['en' => 'Hi there', 'ar' => 'مرحبا'], json_decode($setting->fresh()->value, true));

        $activity = Activity::query()->firstOrFail();
        $this->assertSame('footer_description_1', $activity->properties['key']);
        $this->assertSame('{"en":"Hello","ar":"مرحبا"}', $activity->properties['old_value']);
        $this->assertSame('{"en":"Hi there","ar":"مرحبا"}', $activity->properties['new_value']);
    }

    public function test_no_op_save_of_a_translatable_setting_does_not_touch_or_audit_it(): void
    {
        // Stored with the locales in the opposite order to the form's — still the same translations.
        $setting = $this->translatableSetting(rawJson: '{"ar":"مرحبا","en":"Hello"}');
        $updatedAt = $setting->updated_at;
        $this->travel(5)->minutes();

        Livewire::actingAs($this->superAdmin(), 'admin')->test(SettingsPage::class)->call('save');

        $this->assertEquals($updatedAt->timestamp, $setting->fresh()->updated_at->timestamp);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_json_setting_that_is_not_a_locale_map_stays_a_raw_textarea(): void
    {
        $setting = Setting::factory()->create([
            'key' => 'menu_config', 'value' => '{"columns":3}', 'value_type' => SettingValueType::Json,
            'group' => 'general', 'is_public' => true,
        ]);

        Livewire::actingAs($this->superAdmin(), 'admin')->test(SettingsPage::class)
            ->assertFormFieldExists("setting_{$setting->id}", 'form', fn ($field): bool => $field instanceof Textarea)
            ->assertSet("data.setting_{$setting->id}", '{"columns":3}');
    }

    public function test_store_link_settings_must_be_urls(): void
    {
        $setting = Setting::factory()->create([
            'key' => 'app_store_url', 'value' => '', 'value_type' => SettingValueType::String,
            'group' => 'general', 'is_public' => true,
        ]);

        Livewire::actingAs($this->superAdmin(), 'admin')->test(SettingsPage::class)
            ->set("data.setting_{$setting->id}", 'not a url')->call('save')
            ->assertHasFormErrors(["setting_{$setting->id}"]);

        Livewire::actingAs($this->superAdmin(), 'admin')->test(SettingsPage::class)
            ->set("data.setting_{$setting->id}", 'https://apps.apple.com/app/z-medix')->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('https://apps.apple.com/app/z-medix', $setting->fresh()->value);
    }
}
