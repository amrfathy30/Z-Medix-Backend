<?php

namespace Tests\Feature\Cms;

use App\Models\Page;
use App\Models\PageSection;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use Database\Seeders\PageSectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 4: final QA sweep after Phase 3I — verifies the things that don't
 * fit naturally into the earlier Phase3 schema tests or WebsiteContentTest:
 * the deleted permission, and that a full fresh seed produces exactly the
 * pages and sections WebsiteContentDefinitions defines, with nothing
 * missing and nothing extra.
 */
class Phase4FinalQaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_manage_advanced_permission_no_longer_exists(): void
    {
        $this->assertFalse(
            Permission::where('name', 'pages.manage_advanced')->exists()
        );
    }

    public function test_fresh_seed_creates_exactly_the_defined_pages_and_sections(): void
    {
        $this->seed(PageSectionSeeder::class);

        $registry = app(ContentDefinitionRegistry::class);
        $designedKeys = ['home', 'about-us', 'services', 'privacy-policy', 'terms-and-conditions', 'contact-us'];

        $this->assertSame($designedKeys, Page::query()->orderBy('id')->pluck('key')->all());

        foreach ($designedKeys as $pageKey) {
            $page = Page::query()->where('key', $pageKey)->firstOrFail();
            $definedSectionKeys = array_map(
                fn ($section) => $section->sectionKey,
                $registry->forPage($pageKey)->orderedSections()
            );

            $seededSectionKeys = PageSection::query()
                ->where('page_id', $page->id)
                ->orderBy('sort_order')
                ->pluck('section_key')
                ->all();

            $this->assertSame($definedSectionKeys, $seededSectionKeys, "Section keys for page '{$pageKey}' do not match WebsiteContentDefinitions.");
        }
    }

    public function test_page_sections_response_page_key_field_is_relation_derived_not_a_column(): void
    {
        $this->assertFalse(Schema::hasColumn('page_sections', 'page_key'));

        $this->seed(PageSectionSeeder::class);

        $response = $this->getJson('/api/public/pages/home/sections')->assertOk();

        $this->assertSame('home', $response->json('data.sections.0.page_key'));
    }
}
