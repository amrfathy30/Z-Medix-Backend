<?php

namespace Tests\Feature\Cms\PublicApi;

use App\Enums\SettingValueType;
use App\Models\Setting;
use Database\Seeders\CmsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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

    /** @return mixed The resolved value of one setting from a settings response. */
    private function settingValue(TestResponse $response, string $group, string $key): mixed
    {
        $setting = collect($response->json("data.{$group}"))->firstWhere('key', $key);

        $this->assertNotNull($setting, "Setting '{$key}' is missing from group '{$group}'.");

        return $setting['value'];
    }

    public function test_website_information_settings_are_seeded_in_their_groups(): void
    {
        $this->seed(CmsSeeder::class);

        $response = $this->getJson($this->url)->assertOk();

        foreach (['site_name', 'site_logo', 'app_store_url', 'google_play_url'] as $key) {
            $this->assertContains($key, collect($response->json('data.general'))->pluck('key')->all());
        }

        foreach (['footer_description_1', 'footer_description_2', 'footer_copyright'] as $key) {
            $this->assertContains($key, collect($response->json('data.footer'))->pluck('key')->all());
        }

        foreach (['social_behance', 'social_instagram', 'social_linkedin'] as $key) {
            $this->assertContains($key, collect($response->json('data.social'))->pluck('key')->all());
        }

        $this->assertSame('Z-MEDIX', $this->settingValue($response, 'general', 'site_name'));
        $this->assertNull($this->settingValue($response, 'general', 'site_logo'));
    }

    public function test_translatable_footer_settings_resolve_to_the_request_locale(): void
    {
        $this->seed(CmsSeeder::class);

        $en = $this->getJson($this->url.'?lang=en')->assertOk();
        $ar = $this->getJson($this->url.'?lang=ar')->assertOk();

        $this->assertSame('© 2026 Z-MEDIX. All rights reserved.', $this->settingValue($en, 'footer', 'footer_copyright'));
        $this->assertSame('© 2026 Z-MEDIX. جميع الحقوق محفوظة.', $this->settingValue($ar, 'footer', 'footer_copyright'));
        $this->assertSame('Your smarter way to learn medicine.', $this->settingValue($en, 'footer', 'footer_description_1'));
        $this->assertSame('طريقتك الأذكى لتعلّم الطب.', $this->settingValue($ar, 'footer', 'footer_description_1'));
        $this->assertIsString($this->settingValue($en, 'footer', 'footer_description_2'));
    }
}
