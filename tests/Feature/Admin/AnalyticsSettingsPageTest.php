<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\AnalyticsSettingsPage;
use App\Models\Admin;
use App\Models\GoogleAnalyticsSetting;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AnalyticsSettingsPageTest extends TestCase
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

    public function test_stored_secrets_are_never_hydrated_into_livewire_state(): void
    {
        GoogleAnalyticsSetting::current()->update([
            'api_secret' => 'stored-api-secret',
            'service_account_json' => '{"private_key":"stored-private-key"}',
        ]);

        $component = Livewire::actingAs($this->superAdmin(), 'admin')->test(AnalyticsSettingsPage::class)
            ->assertSet('data.api_secret', null)
            ->assertSet('data.service_account_json', null);

        $payload = json_encode($component->get('data'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('stored-api-secret', $payload);
        $this->assertStringNotContainsString('stored-private-key', $payload);
    }

    public function test_blank_secret_fields_preserve_existing_credentials(): void
    {
        GoogleAnalyticsSetting::current()->update([
            'api_secret' => 'stored-api-secret',
            'service_account_json' => '{"private_key":"stored-private-key"}',
        ]);

        Livewire::actingAs($this->superAdmin(), 'admin')->test(AnalyticsSettingsPage::class)
            ->set('data.measurement_id', 'G-ABC123')
            ->call('save')->assertHasNoFormErrors();

        $setting = GoogleAnalyticsSetting::current();
        $this->assertSame('stored-api-secret', $setting->api_secret);
        $this->assertSame('{"private_key":"stored-private-key"}', $setting->service_account_json);
    }

    public function test_secret_audit_never_contains_raw_values(): void
    {
        $admin = $this->superAdmin();
        GoogleAnalyticsSetting::current()->update(['api_secret' => 'old-secret']);

        Livewire::actingAs($admin, 'admin')->test(AnalyticsSettingsPage::class)
            ->set('data.api_secret', 'new-secret')->call('save')->assertHasNoFormErrors();

        $activity = Activity::query()->latest('id')->firstOrFail();
        $encoded = $activity->properties->toJson();
        $this->assertSame('analytics_settings.secret_updated', $activity->description);
        $this->assertStringNotContainsString('old-secret', $encoded);
        $this->assertStringNotContainsString('new-secret', $encoded);
    }
}
