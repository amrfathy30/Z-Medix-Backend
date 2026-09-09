<?php

namespace Tests\Feature\Cms;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Enums\ContentStatus;
use App\Filament\Pages\SettingsPage;
use App\Filament\Pages\WebsiteContentPage;
use App\Filament\Pages\WebsiteContentSectionsPage;
use App\Filament\Resources\PageSectionResource;
use App\Filament\Resources\PageSectionResource\Pages\EditPageSection;
use App\Models\Admin;
use App\Models\Page;
use App\Models\PageSection;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function adminWithRole(string $roleName): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::Admin,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole($roleName);

        return $admin;
    }

    private function superAdmin(): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::SuperAdmin,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    // ─── WebsiteContentPage ──────────────────────────────────────────────────

    public function test_super_admin_can_access_website_content_page(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentPage::class)
            ->assertOk();
    }

    public function test_content_manager_can_access_website_content_page(): void
    {
        Livewire::actingAs($this->adminWithRole('content_manager'), 'admin')
            ->test(WebsiteContentPage::class)
            ->assertOk();
    }

    public function test_admin_view_only_can_access_website_content_page(): void
    {
        Livewire::actingAs($this->adminWithRole('admin'), 'admin')
            ->test(WebsiteContentPage::class)
            ->assertOk();
    }

    public function test_website_content_page_shows_defined_pages_footer_and_crawler_files(): void
    {
        $component = Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentPage::class);

        $this->assertCount(8, $component->instance()->getCards());
    }

    public function test_website_content_page_has_no_create_or_add_page_button(): void
    {
        // PageResource was deleted in Phase 3I — there is no generic "create
        // page" URL left to link to anywhere in the admin.
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentPage::class)
            ->assertDontSeeHtml('/admin/pages/create');
    }

    public function test_no_website_content_card_links_to_page_resource(): void
    {
        $component = Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentPage::class);

        $urls = collect($component->instance()->getCards())->pluck('url')->filter();

        foreach ($urls as $url) {
            $this->assertStringNotContainsString('/admin/pages', (string) $url);
        }
    }

    public function test_privacy_and_terms_cards_resolve_to_sections_page_urls(): void
    {
        $component = Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentPage::class);

        $cards = collect($component->instance()->getCards())->keyBy('key');

        $this->assertSame(WebsiteContentSectionsPage::getUrl(['pageKey' => 'privacy-policy']), $cards['privacy-policy']['url']);
        $this->assertSame(WebsiteContentSectionsPage::getUrl(['pageKey' => 'terms-and-conditions']), $cards['terms-and-conditions']['url']);
        $this->assertFalse($cards['privacy-policy']['disabled']);
        $this->assertFalse($cards['terms-and-conditions']['disabled']);
    }

    public function test_footer_card_resolves_to_settings_page_url(): void
    {
        $component = Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentPage::class);

        $cards = collect($component->instance()->getCards())->keyBy('key');

        $this->assertSame(SettingsPage::getUrl(), $cards['footer']['url']);
    }

    // ─── WebsiteContentSectionsPage ──────────────────────────────────────────

    public function test_sections_page_loads_for_all_known_page_keys(): void
    {
        $admin = $this->superAdmin();

        foreach (['home', 'about-us', 'services', 'privacy-policy', 'terms-and-conditions'] as $pageKey) {
            Livewire::actingAs($admin, 'admin')
                ->test(WebsiteContentSectionsPage::class, ['pageKey' => $pageKey])
                ->assertOk();
        }
    }

    public function test_unknown_page_key_returns_404(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentSectionsPage::class, ['pageKey' => 'does-not-exist'])
            ->assertNotFound();
    }

    public function test_sections_are_listed_in_sort_order(): void
    {
        $page = Page::factory()->create(['key' => 'home']);
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero', 'sort_order' => 2]);
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'features', 'sort_order' => 1]);
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'faq', 'sort_order' => 3]);

        $component = Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentSectionsPage::class, ['pageKey' => 'home']);

        $keys = $component->instance()->getSections()->pluck('section_key')->all();

        $this->assertSame(['features', 'hero', 'faq'], $keys);
    }

    public function test_sections_page_shows_draft_sections_too(): void
    {
        $page = Page::factory()->create(['key' => 'home']);
        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'how_it_works',
            'status' => ContentStatus::Draft,
        ]);

        $component = Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentSectionsPage::class, ['pageKey' => 'home']);

        $keys = $component->instance()->getSections()->pluck('section_key')->all();

        $this->assertContains('how_it_works', $keys);
    }

    public function test_sections_page_has_no_create_section_button(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentSectionsPage::class, ['pageKey' => 'home'])
            ->assertOk();

        $this->assertArrayNotHasKey('create', PageSectionResource::getPages());
    }

    public function test_section_edit_links_point_to_correct_page_section_resource_edit_urls(): void
    {
        $page = Page::factory()->create(['key' => 'home']);
        $section = PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);

        $component = Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(WebsiteContentSectionsPage::class, ['pageKey' => 'home']);

        $this->assertSame(
            EditPageSection::getUrl(['record' => $section]),
            $component->instance()->getEditUrl($section)
        );
    }

    // ─── Navigation visibility ───────────────────────────────────────────────

    public function test_content_manager_does_not_see_page_section_resource_in_sidebar(): void
    {
        $this->actingAs($this->adminWithRole('content_manager'), 'admin');

        $this->assertFalse(PageSectionResource::shouldRegisterNavigation());
    }

    public function test_super_admin_does_not_see_page_section_resource_in_sidebar(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $this->assertFalse(PageSectionResource::shouldRegisterNavigation());
    }

    public function test_website_content_page_is_still_registered_in_navigation(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $this->assertTrue(WebsiteContentPage::shouldRegisterNavigation());
    }

    // ─── Create/delete gating ────────────────────────────────────────────────

    public function test_no_one_can_create_page_sections_including_super_admin(): void
    {
        $this->assertFalse(PageSectionResource::canCreate());
    }

    public function test_content_manager_can_still_edit_existing_page_section(): void
    {
        // PageSectionResource's form is definition-driven — use a real,
        // definition-backed page key/section_key rather than random factory data.
        $page = Page::factory()->create(['key' => 'home']);
        $section = PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);

        Livewire::actingAs($this->adminWithRole('content_manager'), 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertOk();
    }
}
