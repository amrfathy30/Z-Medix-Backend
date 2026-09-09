<?php

namespace Tests\Feature\Cms;

use App\Enums\ContentStatus;
use App\Models\Page;
use App\Models\PageSection;
use App\Support\Content\Migrations\PageSchemaPreflightCheck;
use Database\Seeders\PageSectionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 3H: the Website Content schema is finalized — every legacy bridge
 * column (page_sections.page_key/type/is_public/title/subtitle/body and
 * pages.slug/title/content) is dropped, while pages.key (unique),
 * page_sections.page_id (FK), and page_sections(page_id, section_key)
 * (unique) all remain.
 *
 * pages.meta_title/meta_description/published_at were also dropped here, then
 * deliberately reinstated by add_seo_and_published_at_to_pages_table — moving
 * SEO into the `content` section left the section-driven pages, which own no
 * such section, with no page-level SEO or publication date. They are asserted
 * as present below rather than absent; see Phase3iSchemaHardeningTest.
 */
class Phase3hFinalSchemaTest extends TestCase
{
    use RefreshDatabase;

    // ─── Schema: legacy columns are gone ───────────────────────────────────────

    public function test_page_sections_no_longer_has_legacy_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('page_sections', 'page_key'));
        $this->assertFalse(Schema::hasColumn('page_sections', 'type'));
        $this->assertFalse(Schema::hasColumn('page_sections', 'is_public'));
        $this->assertFalse(Schema::hasColumn('page_sections', 'title'));
        $this->assertFalse(Schema::hasColumn('page_sections', 'subtitle'));
        $this->assertFalse(Schema::hasColumn('page_sections', 'body'));
    }

    public function test_pages_no_longer_has_legacy_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('pages', 'slug'));
        $this->assertFalse(Schema::hasColumn('pages', 'title'));
        $this->assertFalse(Schema::hasColumn('pages', 'content'));
        // Reinstated after Phase 3H — see the class docblock.
        $this->assertTrue(Schema::hasColumn('pages', 'meta_title'));
        $this->assertTrue(Schema::hasColumn('pages', 'meta_description'));
        $this->assertTrue(Schema::hasColumn('pages', 'published_at'));
    }

    public function test_final_schema_columns_remain(): void
    {
        $this->assertTrue(Schema::hasColumns('pages', ['id', 'key', 'label', 'status', 'sort_order', 'created_by_admin_id', 'updated_by_admin_id', 'created_at', 'updated_at', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('page_sections', ['id', 'page_id', 'section_key', 'label', 'sort_order', 'status', 'data', 'created_by_admin_id', 'updated_by_admin_id', 'created_at', 'updated_at', 'deleted_at']));
    }

    // ─── Schema: constraints ────────────────────────────────────────────────────

    public function test_pages_key_unique_constraint_remains(): void
    {
        Page::factory()->create(['key' => 'unique-check']);

        $this->expectException(QueryException::class);

        Page::factory()->create(['key' => 'unique-check']);
    }

    public function test_page_sections_page_id_section_key_unique_constraint_remains(): void
    {
        $page = Page::factory()->create();
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);

        $this->expectException(QueryException::class);

        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);
    }

    public function test_page_sections_page_id_foreign_key_remains(): void
    {
        $this->expectException(QueryException::class);

        PageSection::factory()->create(['page_id' => 999999]);
    }

    public function test_old_page_key_section_key_unique_index_is_gone(): void
    {
        // Two distinct pages can now both have a section with the same
        // section_key — the old unique(page_key, section_key) index, which
        // would have blocked this, no longer exists.
        $first = Page::factory()->create();
        $second = Page::factory()->create();

        PageSection::factory()->create(['page_id' => $first->id, 'section_key' => 'hero']);
        $duplicate = PageSection::factory()->create(['page_id' => $second->id, 'section_key' => 'hero']);

        $this->assertNotNull($duplicate->id);
    }

    public function test_old_pages_slug_unique_index_is_gone(): void
    {
        // No slug column at all anymore, so nothing to assert beyond the
        // column-existence check above — this test documents the intent.
        $this->assertFalse(Schema::hasColumn('pages', 'slug'));
    }

    // ─── Preflight check (re-runnable after the columns are gone) ──────────────

    public function test_preflight_is_clean_after_seeding(): void
    {
        $this->seed(PageSectionSeeder::class);

        $this->assertTrue(PageSchemaPreflightCheck::isClean());
        $this->assertSame([], PageSchemaPreflightCheck::violations());
    }

    // Phase 3I made pages.key/page_sections.page_id NOT NULL at the DB
    // level, so a null key/page_id can no longer reach the preflight check
    // at all — the DB itself rejects the insert first. That scenario is now
    // covered by Phase3iSchemaHardeningTest's NOT NULL constraint tests
    // instead of via the preflight check.

    public function test_preflight_does_not_require_static_pages_to_already_exist(): void
    {
        // A brand-new install with no pages rows at all yet has nothing to
        // lose — the preflight must not demand privacy-policy/terms rows
        // exist before they've ever been seeded.
        $this->assertSame([], PageSchemaPreflightCheck::violations());
    }

    public function test_preflight_flags_existing_static_page_missing_a_content_section(): void
    {
        Page::factory()->create(['key' => 'privacy-policy']);

        $violations = PageSchemaPreflightCheck::violations(
            requiredPageKeys: ['privacy-policy'],
            requiredSections: [['page_key' => 'privacy-policy', 'section_key' => 'content']],
        );

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString("Page 'privacy-policy'", implode(' ', $violations));
    }

    // ─── Models ─────────────────────────────────────────────────────────────────

    public function test_page_model_has_no_legacy_attributes(): void
    {
        $page = Page::factory()->create();

        $this->assertArrayNotHasKey('slug', $page->getAttributes());
        $this->assertArrayNotHasKey('title', $page->getAttributes());
        $this->assertArrayNotHasKey('content', $page->getAttributes());
    }

    /**
     * The counterpart to the assertions above: the three columns Phase 3H
     * dropped and the SEO migration reinstated are carried by the model
     * again. published_at is asserted on the instance because the factory
     * creates a published page and the model stamps it on save; the meta
     * columns are asserted on the model's declarations, since a factory row
     * sets neither and an unset attribute is simply absent.
     */
    public function test_page_model_carries_the_reinstated_seo_attributes(): void
    {
        $page = Page::factory()->create();

        $this->assertArrayHasKey('published_at', $page->getAttributes());
        $this->assertNotNull($page->published_at);

        $this->assertContains('meta_title', $page->getFillable());
        $this->assertContains('meta_description', $page->getFillable());

        $this->assertContains('meta_title', $page->getTranslatableAttributes());
        $this->assertContains('meta_description', $page->getTranslatableAttributes());
    }

    public function test_page_section_model_has_no_legacy_attributes(): void
    {
        $section = PageSection::factory()->create();

        $this->assertArrayNotHasKey('page_key', $section->getAttributes());
        $this->assertArrayNotHasKey('type', $section->getAttributes());
        $this->assertArrayNotHasKey('is_public', $section->getAttributes());
        $this->assertArrayNotHasKey('title', $section->getAttributes());
        $this->assertArrayNotHasKey('subtitle', $section->getAttributes());
        $this->assertArrayNotHasKey('body', $section->getAttributes());
    }

    public function test_scope_of_page_works(): void
    {
        $page = Page::factory()->create();
        $other = Page::factory()->create();
        $matching = PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);
        PageSection::factory()->create(['page_id' => $other->id, 'section_key' => 'hero']);

        $results = PageSection::query()->ofPage($page)->get();

        $this->assertCount(1, $results);
        $this->assertSame($matching->id, $results->first()->id);
    }

    public function test_scope_for_page_key_resolves_through_page_and_page_id(): void
    {
        $page = Page::factory()->create(['key' => 'resolved-page']);
        $matching = PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);

        $results = PageSection::query()->forPageKey('resolved-page')->get();

        $this->assertCount(1, $results);
        $this->assertSame($matching->id, $results->first()->id);
    }

    // ─── Seeder ─────────────────────────────────────────────────────────────────

    public function test_seeder_creates_all_definition_pages_and_sections_including_static_pages(): void
    {
        $this->seed(PageSectionSeeder::class);

        foreach (['home', 'about-us', 'services', 'privacy-policy', 'terms-and-conditions'] as $pageKey) {
            $page = Page::query()->where('key', $pageKey)->first();
            $this->assertNotNull($page, "Expected a Page row for {$pageKey}.");
            $this->assertSame(ContentStatus::Published, $page->status);
        }

        $privacy = Page::query()->where('key', 'privacy-policy')->firstOrFail();
        $terms = Page::query()->where('key', 'terms-and-conditions')->firstOrFail();
        $this->assertTrue(PageSection::query()->where('page_id', $privacy->id)->where('section_key', 'content')->exists());
        $this->assertTrue(PageSection::query()->where('page_id', $terms->id)->where('section_key', 'content')->exists());
    }
}
