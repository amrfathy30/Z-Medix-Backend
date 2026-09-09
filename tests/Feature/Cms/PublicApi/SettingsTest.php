<?php

namespace Tests\Feature\Cms\PublicApi;

use App\Enums\SettingValueType;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/settings';

    public function test_only_public_settings_are_returned(): void
    {
        Setting::factory()->create(['key' => 'public_key', 'is_public' => true, 'group' => 'general']);
        Setting::factory()->private()->create(['key' => 'private_key', 'group' => 'general']);

        $response = $this->getJson($this->url);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $allKeys = collect($response->json('data.general'))->pluck('key')->toArray();
        $this->assertContains('public_key', $allKeys);
        $this->assertNotContains('private_key', $allKeys);
    }

    public function test_settings_are_grouped_by_group(): void
    {
        Setting::factory()->inGroup('general')->create(['key' => 'site_name', 'is_public' => true]);
        Setting::factory()->inGroup('footer')->create(['key' => 'footer_copyright', 'is_public' => true]);

        $response = $this->getJson($this->url);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'general',
                    'footer',
                ],
            ]);
    }

    public function test_private_settings_are_not_leaked(): void
    {
        Setting::factory()->private()->create(['key' => 'internal_secret', 'group' => 'internal']);

        $response = $this->getJson($this->url);

        $response->assertOk();
        $this->assertArrayNotHasKey('internal', $response->json('data'));
    }

    public function test_settings_response_has_key_value_group_fields(): void
    {
        Setting::factory()->create(['key' => 'site_name', 'value' => 'Starter Platform', 'group' => 'general', 'is_public' => true]);

        $response = $this->getJson($this->url);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'general' => [
                        ['key', 'value', 'group'],
                    ],
                ],
            ]);
    }

    public function test_image_setting_returns_an_absolute_public_url(): void
    {
        config()->set('filesystems.disks.public.url', 'https://api.example.test/storage');
        Setting::factory()->create([
            'key' => 'site_logo',
            'value' => 'settings/logo.webp',
            'value_type' => SettingValueType::Image,
            'group' => 'general',
            'is_public' => true,
        ]);

        $this->getJson($this->url)->assertOk()
            ->assertJsonPath('data.general.0.value', 'https://api.example.test/storage/settings/logo.webp');
    }
}
