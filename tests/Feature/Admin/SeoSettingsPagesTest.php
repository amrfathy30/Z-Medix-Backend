<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\RobotsSettingsPage;
use App\Filament\Pages\WebsiteContentPageSettingsPage;
use App\Http\Controllers\SitemapController;
use App\Models\Admin;
use App\Models\Page;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SeoSettingsPagesTest extends TestCase
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

    public function test_page_seo_admin_saves_locales_canonical_and_indexability(): void
    {
        $page = Page::factory()->create(['key' => 'home']);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentPageSettingsPage::class, ['pageKey' => 'home'])
            ->set('data.meta_title.en', 'Home')
            ->set('data.meta_title.ar', 'الرئيسية')
            ->set('data.public_path.en', '/en/home')
            ->set('data.public_path.ar', '/ar/home')
            ->set('data.canonical_url', 'https://example.test/home')
            ->set('data.is_indexable', false)
            ->set('data.include_in_sitemap', false)
            ->call('save')->assertHasNoErrors();

        $page->refresh();
        $this->assertSame('Home', $page->getTranslation('meta_title', 'en'));
        $this->assertSame('/ar/home', $page->getTranslation('public_path', 'ar'));
        $this->assertSame('https://example.test/home', $page->canonical_url);
        $this->assertFalse($page->is_indexable);
        $this->assertFalse($page->include_in_sitemap);
    }

    public function test_robots_admin_requires_confirmation_before_disallow_all(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')->test(RobotsSettingsPage::class)
            ->set('data.robots_body', "User-agent: *\nDisallow: /")->call('save');

        $this->assertDatabaseMissing('settings', ['key' => SitemapController::ROBOTS_SETTING_KEY]);
    }

    public function test_robots_admin_creates_a_private_setting_on_first_safe_save(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')->test(RobotsSettingsPage::class)
            ->set('data.robots_body', "User-agent: *\nDisallow: /admin")->call('save');

        $this->assertDatabaseHas('settings', [
            'key' => SitemapController::ROBOTS_SETTING_KEY,
            'group' => 'seo',
            'is_public' => false,
        ]);
    }
}
