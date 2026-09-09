<?php

namespace Tests\Feature\Sitemap;

use App\Enums\SettingValueType;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaticSitemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('seo.public_base_url', 'https://example.test');
    }

    public function test_dynamic_sitemap_lists_only_eligible_locale_urls(): void
    {
        Page::factory()->create(['public_path' => ['en' => '/en/home', 'ar' => '/ar/home']]);
        Page::factory()->create(['public_path' => ['en' => '/hidden'], 'include_in_sitemap' => false]);

        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringStartsWith('text/xml', (string) $response->headers->get('content-type'));
        $response->assertSee('https://example.test/en/home', false);
        $response->assertSee('https://example.test/ar/home', false);
        $response->assertDontSee('https://example.test/hidden', false);
        $response->assertSee('hreflang="en"', false);
        $response->assertSee('hreflang="ar"', false);
    }

    public function test_robots_txt_has_safe_default_and_appends_sitemap(): void
    {
        $this->get('/robots.txt')->assertOk()
            ->assertSee("User-agent: *\nAllow: /", false)
            ->assertSee('Sitemap: https://example.test/sitemap.xml', false);
    }

    public function test_robots_txt_uses_private_stored_body(): void
    {
        Setting::factory()->private()->create([
            'key' => 'seo.robots_txt_body',
            'value' => "User-agent: *\nDisallow: /private",
            'value_type' => SettingValueType::Text,
            'group' => 'seo',
        ]);

        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /private', false);
    }
}
