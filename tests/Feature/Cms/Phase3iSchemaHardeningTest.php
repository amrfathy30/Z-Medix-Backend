<?php

namespace Tests\Feature\Cms;

use App\Enums\ContentStatus;
use App\Filament\Resources\PageResource;
use App\Models\Page;
use App\Models\PageSection;
use App\Support\Content\Migrations\PageSchemaPreflightCheck;
use Database\Seeders\PageSectionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 3I: closes the last deferred-schema item from Phase 3D/3H —
 * pages.key and page_sections.page_id are now NOT NULL — and confirms the
 * public API no longer exposes `slug` anywhere.
 */
class Phase3iSchemaHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ─── Schema: NOT NULL ───────────────────────────────────────────────────────

    public function test_pages_key_is_not_nullable(): void
    {
        $this->expectException(QueryException::class);

        DB::table('pages')->insert([
            'key' => null,
            'label' => 'No Key',
            'status' => ContentStatus::Draft->value,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_page_sections_page_id_is_not_nullable(): void
    {
        $this->expectException(QueryException::class);

        DB::table('page_sections')->insert([
            'page_id' => null,
            'section_key' => 'content',
            'label' => 'No Page',
            'status' => ContentStatus::Draft->value,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_pages_key_unique_constraint_still_remains(): void
    {
        Page::factory()->create(['key' => 'unique-check']);

        $this->expectException(QueryException::class);

        Page::factory()->create(['key' => 'unique-check']);
    }

    public function test_page_sections_page_id_section_key_unique_constraint_still_remains(): void
    {
        $page = Page::factory()->create();
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);

        $this->expectException(QueryException::class);

        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);
    }

    public function test_page_sections_page_id_foreign_key_still_remains(): void
    {
        $this->expectException(QueryException::class);

        PageSection::factory()->create(['page_id' => 999999]);
    }

    public function test_deleting_a_page_with_sections_is_restricted_not_nulled(): void
    {
        $page = Page::factory()->create();
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);

        $this->expectException(QueryException::class);

        DB::table('pages')->where('id', $page->id)->delete();
    }

    /**
     * pages.meta_title/meta_description/published_at were dropped by Phase 3H
     * and deliberately reinstated afterwards by
     * add_seo_and_published_at_to_pages_table: moving SEO into the `content`
     * section left the section-driven pages (home, about-us, services), which
     * own no such section, with no page-level SEO or publication date at all.
     * They are asserted as present below. The rest of the Phase 3H drop —
     * pages.slug/title/content and the page_sections columns — stands.
     */
    public function test_no_legacy_columns_exist(): void
    {
        $this->assertFalse(Schema::hasColumn('pages', 'slug'));
        $this->assertFalse(Schema::hasColumn('pages', 'title'));
        $this->assertFalse(Schema::hasColumn('pages', 'content'));
        $this->assertTrue(Schema::hasColumn('pages', 'meta_title'));
        $this->assertTrue(Schema::hasColumn('pages', 'meta_description'));
        $this->assertTrue(Schema::hasColumn('pages', 'published_at'));
        $this->assertFalse(Schema::hasColumn('page_sections', 'page_key'));
        $this->assertFalse(Schema::hasColumn('page_sections', 'type'));
        $this->assertFalse(Schema::hasColumn('page_sections', 'is_public'));
        $this->assertFalse(Schema::hasColumn('page_sections', 'title'));
        $this->assertFalse(Schema::hasColumn('page_sections', 'subtitle'));
        $this->assertFalse(Schema::hasColumn('page_sections', 'body'));
    }

    // ─── Preflight is still clean against the hardened schema ─────────────────

    public function test_preflight_is_clean_after_seeding(): void
    {
        $this->seed(PageSectionSeeder::class);

        $this->assertTrue(PageSchemaPreflightCheck::isClean());
        $this->assertSame([], PageSchemaPreflightCheck::violations());
    }

    // ─── Public API: slug is gone, key only ────────────────────────────────────

    public function test_page_api_response_exposes_key_not_slug(): void
    {
        Page::factory()->create(['key' => 'about-us', 'status' => ContentStatus::Published]);

        $this->getJson('/api/public/pages/about-us')
            ->assertOk()
            ->assertJsonPath('data.key', 'about-us')
            ->assertJsonMissingPath('data.slug');
    }

    public function test_privacy_policy_api_works_by_key(): void
    {
        Page::factory()->create(['key' => 'privacy-policy', 'status' => ContentStatus::Published]);

        $this->getJson('/api/public/pages/privacy-policy')
            ->assertOk()
            ->assertJsonPath('data.key', 'privacy-policy')
            ->assertJsonMissingPath('data.slug');
    }

    public function test_terms_and_conditions_api_works_by_key(): void
    {
        Page::factory()->create(['key' => 'terms-and-conditions', 'status' => ContentStatus::Published]);

        $this->getJson('/api/public/pages/terms-and-conditions')
            ->assertOk()
            ->assertJsonPath('data.key', 'terms-and-conditions')
            ->assertJsonMissingPath('data.slug');
    }

    public function test_page_content_still_comes_from_content_section_data(): void
    {
        $page = Page::factory()->create(['key' => 'privacy-policy', 'status' => ContentStatus::Published]);

        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'content',
            'status' => ContentStatus::Published,
            'data' => [
                'title' => ['en' => 'Privacy Policy'],
                'body' => ['en' => 'Body content'],
            ],
        ]);

        $this->getJson('/api/public/pages/privacy-policy')
            ->assertOk()
            ->assertJsonPath('data.title', 'Privacy Policy')
            ->assertJsonPath('data.content', 'Body content');
    }

    public function test_sections_endpoint_still_works_for_all_designed_pages(): void
    {
        $this->seed(PageSectionSeeder::class);

        foreach (['home', 'about-us', 'services', 'privacy-policy', 'terms-and-conditions'] as $key) {
            $this->getJson("/api/public/pages/{$key}/sections")->assertOk();
        }
    }

    public function test_draft_sections_do_not_appear_in_sections_endpoint(): void
    {
        $page = Page::factory()->create(['key' => 'about-us', 'status' => ContentStatus::Published]);
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero', 'status' => ContentStatus::Published]);
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'draft-section', 'status' => ContentStatus::Draft]);

        $response = $this->getJson('/api/public/pages/about-us/sections')->assertOk();

        $sectionKeys = collect($response->json('data.sections'))->pluck('section_key');
        $this->assertContains('hero', $sectionKeys);
        $this->assertNotContains('draft-section', $sectionKeys);
    }

    // ─── Admin: PageResource deletion ──────────────────────────────────────────

    public function test_page_resource_class_no_longer_exists(): void
    {
        // Deleted in Phase 3I: Website Content pages are definition-owned —
        // admins must not be able to create/rename/delete/restore raw Page
        // rows through a generic resource. See WebsiteContentTest and
        // FilamentCmsResourcesTest for the admin/navigation-level coverage.
        $this->assertFalse(class_exists(PageResource::class));
    }

    // ─── Seeder: idempotent and NOT-NULL compatible ────────────────────────────

    public function test_seeder_is_idempotent_under_not_null_columns(): void
    {
        $this->seed(PageSectionSeeder::class);
        $firstPageCount = Page::query()->count();
        $firstSectionCount = PageSection::query()->count();

        $this->seed(PageSectionSeeder::class);

        $this->assertSame($firstPageCount, Page::query()->count());
        $this->assertSame($firstSectionCount, PageSection::query()->count());
    }

    public function test_seeder_does_not_overwrite_admin_edited_section_data(): void
    {
        $this->seed(PageSectionSeeder::class);

        $page = Page::query()->where('key', 'home')->firstOrFail();
        $section = PageSection::query()->where('page_id', $page->id)->firstOrFail();
        $section->forceFill(['data' => ['title' => ['en' => 'Admin Edited Title']]])->save();

        $this->seed(PageSectionSeeder::class);

        $this->assertSame('Admin Edited Title', $section->refresh()->data['title']['en']);
    }
}
