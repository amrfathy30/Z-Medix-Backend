<?php

namespace Tests\Feature\Cms;

use App\Enums\ContentStatus;
use App\Models\Page;
use App\Models\PageSection;
use Database\Seeders\PageSectionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_valid_page_section(): void
    {
        $section = PageSection::factory()->create();

        $this->assertDatabaseHas('page_sections', ['id' => $section->id]);
        $this->assertNotEmpty($section->data['title']['en']);
    }

    public function test_data_is_cast_to_array(): void
    {
        $section = PageSection::factory()->create(['data' => ['foo' => 'bar']]);

        $this->assertIsArray($section->fresh()->data);
        $this->assertSame('bar', $section->fresh()->data['foo']);
    }

    public function test_status_is_cast_to_enum(): void
    {
        $section = PageSection::factory()->create(['status' => ContentStatus::Draft]);

        $this->assertInstanceOf(ContentStatus::class, $section->fresh()->status);
        $this->assertSame(ContentStatus::Draft, $section->fresh()->status);
    }

    public function test_page_id_and_section_key_combination_is_unique(): void
    {
        $page = Page::factory()->create();
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);

        $this->expectException(QueryException::class);

        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);
    }

    public function test_for_page_key_scope_resolves_via_page_relation(): void
    {
        $home = Page::factory()->create(['key' => 'home']);
        $services = Page::factory()->create(['key' => 'services']);
        PageSection::factory()->create(['page_id' => $home->id, 'section_key' => 'hero']);
        PageSection::factory()->create(['page_id' => $services->id, 'section_key' => 'hero']);

        $sections = PageSection::forPageKey('home')->get();

        $this->assertCount(1, $sections);
        $this->assertSame($home->id, $sections->first()->page_id);
    }

    public function test_for_page_key_scope_returns_empty_when_page_does_not_exist(): void
    {
        $sections = PageSection::forPageKey('does-not-exist')->get();

        $this->assertCount(0, $sections);
    }

    public function test_of_page_scope_filters_by_page_id(): void
    {
        $page = Page::factory()->create();
        $other = Page::factory()->create();
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);
        PageSection::factory()->create(['page_id' => $other->id, 'section_key' => 'hero']);

        $sections = PageSection::query()->ofPage($page)->get();

        $this->assertCount(1, $sections);
        $this->assertSame($page->id, $sections->first()->page_id);
    }

    public function test_published_scope_excludes_draft_and_archived(): void
    {
        PageSection::factory()->create(['status' => ContentStatus::Published]);
        PageSection::factory()->create(['status' => ContentStatus::Draft]);
        PageSection::factory()->create(['status' => ContentStatus::Archived]);

        $this->assertSame(1, PageSection::published()->count());
    }

    public function test_ordered_scope_sorts_by_sort_order(): void
    {
        $page = Page::factory()->create(['key' => 'home']);
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'second', 'sort_order' => 2]);
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'first', 'sort_order' => 1]);

        $sections = PageSection::forPageKey('home')->ordered()->get();

        $this->assertSame('first', $sections->first()->section_key);
        $this->assertSame('second', $sections->last()->section_key);
    }

    public function test_soft_deletes_are_supported(): void
    {
        $section = PageSection::factory()->create();

        $section->delete();

        $this->assertSoftDeleted('page_sections', ['id' => $section->id]);
    }

    public function test_page_section_seeder_seeds_all_designed_pages(): void
    {
        $this->seed(PageSectionSeeder::class);

        foreach (['home', 'about-us', 'services', 'privacy-policy', 'terms-and-conditions'] as $pageKey) {
            $this->assertGreaterThan(0, PageSection::forPageKey($pageKey)->count());
        }
    }

    public function test_page_section_seeder_is_idempotent(): void
    {
        $this->seed(PageSectionSeeder::class);
        $countBefore = PageSection::count();

        $this->seed(PageSectionSeeder::class);
        $countAfter = PageSection::count();

        $this->assertSame($countBefore, $countAfter);
    }
}
