<?php

namespace Tests\Feature\Cms;

use App\Enums\ContentStatus;
use App\Models\BlogCategory;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\Page;
use App\Models\Setting;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CmsFoundationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Factories ────────────────────────────────────────────────────────────

    public function test_page_factory_creates_published_record(): void
    {
        $page = Page::factory()->create();

        $this->assertDatabaseHas('pages', ['id' => $page->id]);
        $this->assertNotEmpty($page->key);
        $this->assertNotEmpty($page->label);
        $this->assertSame(ContentStatus::Published, $page->status);
    }

    public function test_page_factory_draft_state_sets_status(): void
    {
        $page = Page::factory()->draft()->create();

        $this->assertSame(ContentStatus::Draft, $page->status);
    }

    public function test_blog_category_factory_creates_valid_record(): void
    {
        $category = BlogCategory::factory()->create();

        $this->assertDatabaseHas('blog_categories', ['id' => $category->id]);
        $this->assertNotEmpty($category->getTranslation('name', 'en'));
    }

    public function test_faq_category_factory_creates_valid_record(): void
    {
        $category = FaqCategory::factory()->create();

        $this->assertDatabaseHas('faq_categories', ['id' => $category->id]);
    }

    public function test_faq_factory_creates_valid_record_with_category(): void
    {
        $faq = Faq::factory()->create();

        $this->assertDatabaseHas('faqs', ['id' => $faq->id]);
        $this->assertNotNull($faq->faq_category_id);
    }

    public function test_setting_factory_creates_public_record_by_default(): void
    {
        $setting = Setting::factory()->create();

        $this->assertDatabaseHas('settings', ['id' => $setting->id]);
        $this->assertTrue($setting->is_public);
    }

    public function test_setting_factory_private_state(): void
    {
        $setting = Setting::factory()->private()->create();

        $this->assertFalse($setting->is_public);
    }

    // ─── Permissions ─────────────────────────────────────────────────────────

    public function test_cms_permissions_are_seeded(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $expectedPermissions = [
            'content.view', 'content.create', 'content.update', 'content.delete', 'content.publish',
            'settings.view', 'settings.update',
            'contact_messages.view', 'contact_messages.update', 'contact_messages.delete',
        ];

        foreach ($expectedPermissions as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission, 'guard_name' => 'admin']);
        }
    }

    public function test_existing_admin_and_role_permissions_are_preserved(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $existing = ['admins.view', 'admins.create', 'admins.update', 'admins.delete', 'roles.view', 'roles.create', 'roles.update', 'roles.delete'];

        foreach ($existing as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission, 'guard_name' => 'admin']);
        }
    }

    public function test_content_manager_has_all_content_permissions(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $role = Role::findByName('content_manager', 'admin');

        foreach (['content.view', 'content.create', 'content.update', 'content.delete', 'content.publish'] as $permission) {
            $this->assertTrue($role->hasPermissionTo($permission, 'admin'), "content_manager missing: {$permission}");
        }
    }

    public function test_content_manager_has_settings_permissions(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $role = Role::findByName('content_manager', 'admin');

        $this->assertTrue($role->hasPermissionTo('settings.view', 'admin'));
        $this->assertTrue($role->hasPermissionTo('settings.update', 'admin'));
    }

    public function test_content_manager_has_contact_message_view_and_update(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $role = Role::findByName('content_manager', 'admin');

        $this->assertTrue($role->hasPermissionTo('contact_messages.view', 'admin'));
        $this->assertTrue($role->hasPermissionTo('contact_messages.update', 'admin'));
        $this->assertFalse($role->hasPermissionTo('contact_messages.delete', 'admin'));
    }

    public function test_admin_role_has_content_view_only(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $role = Role::findByName('admin', 'admin');

        $this->assertTrue($role->hasPermissionTo('content.view', 'admin'));
        $this->assertFalse($role->hasPermissionTo('content.create', 'admin'));
        $this->assertFalse($role->hasPermissionTo('content.delete', 'admin'));
        $this->assertFalse($role->hasPermissionTo('content.publish', 'admin'));
    }

    public function test_super_admin_has_all_permissions(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $role = Role::findByName('super_admin', 'admin');
        $allPermissions = Permission::where('guard_name', 'admin')->pluck('name');

        foreach ($allPermissions as $permission) {
            $this->assertTrue($role->hasPermissionTo($permission, 'admin'));
        }
    }

    // ─── CMS Seeder ──────────────────────────────────────────────────────────

    public function test_cms_seeder_no_longer_creates_website_content_pages(): void
    {
        $this->seed(CmsSeeder::class);

        // Since Phase 3G, these Page rows are owned by PageSectionSeeder +
        // WebsiteContentDefinitions, not CmsSeeder.
        foreach (['about-us', 'privacy-policy', 'terms-and-conditions', 'services'] as $key) {
            $this->assertDatabaseMissing('pages', ['key' => $key]);
        }
    }

    public function test_cms_seeder_creates_faq_categories(): void
    {
        $this->seed(CmsSeeder::class);

        foreach (['general', 'getting-started', 'billing'] as $slug) {
            $this->assertDatabaseHas('faq_categories', ['slug' => $slug]);
        }
    }

    public function test_cms_seeder_creates_faqs_linked_to_categories(): void
    {
        $this->seed(CmsSeeder::class);

        $this->assertGreaterThanOrEqual(4, Faq::count());

        $general = FaqCategory::where('slug', 'general')->first();
        $this->assertGreaterThanOrEqual(1, $general->faqs()->count());
    }

    public function test_cms_seeder_creates_blog_categories(): void
    {
        $this->seed(CmsSeeder::class);

        foreach (['announcements', 'tutorials', 'updates'] as $slug) {
            $this->assertDatabaseHas('blog_categories', ['slug' => $slug]);
        }
    }

    public function test_cms_seeder_creates_settings_in_all_groups(): void
    {
        $this->seed(CmsSeeder::class);

        foreach (['general', 'home', 'footer', 'social'] as $group) {
            $this->assertDatabaseHas('settings', ['group' => $group]);
        }
    }

    public function test_cms_seeder_creates_key_home_settings(): void
    {
        $this->seed(CmsSeeder::class);

        foreach (['hero_title', 'hero_subtitle', 'hero_primary_cta_text', 'testimonials_title', 'cta_banner_title', 'faq_title', 'footer_copyright'] as $key) {
            $this->assertDatabaseHas('settings', ['key' => $key]);
        }
    }

    public function test_cms_seeder_is_idempotent(): void
    {
        $this->seed(CmsSeeder::class);
        $this->seed(CmsSeeder::class);

        $this->assertSame(1, BlogCategory::where('slug', 'announcements')->count());
        $this->assertSame(1, Setting::where('key', 'site_name')->count());
    }

    // ─── Locale Middleware ────────────────────────────────────────────────────

    public function test_locale_middleware_sets_arabic_from_lang_query_param(): void
    {
        $this->getJson('/api/public/ping?lang=ar');

        $this->assertSame('ar', App::getLocale());
    }

    public function test_locale_middleware_sets_english_from_lang_query_param(): void
    {
        $this->getJson('/api/public/ping?lang=en');

        $this->assertSame('en', App::getLocale());
    }

    public function test_locale_middleware_sets_arabic_from_accept_language_header(): void
    {
        $this->getJson('/api/public/ping', ['Accept-Language' => 'ar']);

        $this->assertSame('ar', App::getLocale());
    }

    public function test_locale_middleware_sets_english_from_accept_language_header(): void
    {
        $this->getJson('/api/public/ping', ['Accept-Language' => 'en']);

        $this->assertSame('en', App::getLocale());
    }

    public function test_locale_middleware_parses_complex_accept_language_header(): void
    {
        $this->getJson('/api/public/ping', ['Accept-Language' => 'ar-EG,ar;q=0.9,en;q=0.8']);

        $this->assertSame('ar', App::getLocale());
    }

    public function test_locale_middleware_falls_back_to_english_for_unsupported_locale(): void
    {
        $this->getJson('/api/public/ping', ['Accept-Language' => 'fr']);

        $this->assertSame('en', App::getLocale());
    }

    public function test_locale_middleware_defaults_to_english_when_no_locale_given(): void
    {
        $this->getJson('/api/public/ping');

        $this->assertSame('en', App::getLocale());
    }

    public function test_lang_query_param_takes_priority_over_accept_language_header(): void
    {
        $this->getJson('/api/public/ping?lang=ar', ['Accept-Language' => 'en']);

        $this->assertSame('ar', App::getLocale());
    }

    public function test_invalid_lang_query_param_falls_back_to_accept_language(): void
    {
        $this->getJson('/api/public/ping?lang=fr', ['Accept-Language' => 'ar']);

        $this->assertSame('ar', App::getLocale());
    }

    // ─── Scope compliance ─────────────────────────────────────────────────────

    public function test_registration_is_only_exposed_on_the_public_auth_prefix(): void
    {
        // Student registration now exists; 422 proves the route is reachable and
        // validated. It must not be mirrored on the admin prefix.
        $this->postJson('/api/public/auth/register', [])->assertStatus(422);
        $this->postJson('/api/admin/auth/register', [])->assertStatus(404);
    }

    public function test_no_lex_packages_route_exists(): void
    {
        $this->getJson('/api/public/lex-packages')->assertStatus(404);
    }

    public function test_no_public_lecturers_route_exists(): void
    {
        $this->getJson('/api/public/lecturers')->assertStatus(404);
    }

    public function test_no_testimonials_table_exists(): void
    {
        $this->assertFalse(Schema::hasTable('testimonials'));
    }
}
