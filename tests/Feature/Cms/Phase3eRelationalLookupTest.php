<?php

namespace Tests\Feature\Cms;

use App\Enums\ContentStatus;
use App\Models\Page;
use App\Models\PageSection;
use Database\Seeders\PageSectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Page/page_id relational lookups across the seeder — page_key/scopeOfPage/
 * scopeForPageKey themselves are covered directly in PageSectionTest.
 */
class Phase3eRelationalLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_sections_with_page_id_set(): void
    {
        $this->seed(PageSectionSeeder::class);

        $page = Page::query()->where('key', 'home')->firstOrFail();
        $hero = PageSection::query()->where('page_id', $page->id)->where('section_key', 'hero')->first();

        $this->assertNotNull($hero);
    }

    public function test_seeder_creates_the_page_row_for_designed_pages(): void
    {
        $this->seed(PageSectionSeeder::class);

        foreach (['home', 'about-us', 'services', 'privacy-policy', 'terms-and-conditions'] as $pageKey) {
            $page = Page::query()->where('key', $pageKey)->first();

            $this->assertNotNull($page, "Expected a Page row for key {$pageKey}.");
            $this->assertSame(ContentStatus::Published, $page->status);
        }
    }

    public function test_seeder_does_not_create_duplicate_rows_when_page_already_exists(): void
    {
        Page::factory()->create(['key' => 'home', 'status' => ContentStatus::Published]);

        $this->seed(PageSectionSeeder::class);

        $this->assertSame(1, Page::query()->where('key', 'home')->count());
    }

    public function test_seeder_is_idempotent_on_page_id_based_lookup(): void
    {
        $this->seed(PageSectionSeeder::class);
        $countAfterFirstRun = PageSection::query()->count();

        $this->seed(PageSectionSeeder::class);
        $countAfterSecondRun = PageSection::query()->count();

        $this->assertSame($countAfterFirstRun, $countAfterSecondRun);
    }
}
