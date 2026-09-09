<?php

namespace Tests\Feature\Cms;

use App\Enums\ContentStatus;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\PageSectionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageSectionItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_section_contract_includes_only_published_ordered_items(): void
    {
        $page = Page::factory()->create(['key' => 'home', 'status' => ContentStatus::Published]);
        $section = PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'hero',
            'status' => ContentStatus::Published,
        ]);
        PageSectionItem::factory()->forSection($section)->create(['title' => ['en' => 'Second'], 'sort_order' => 2]);
        PageSectionItem::factory()->forSection($section)->create(['title' => ['en' => 'First'], 'sort_order' => 1]);
        PageSectionItem::factory()->forSection($section)->draft()->create(['title' => ['en' => 'Hidden'], 'sort_order' => 0]);

        $this->getJson('/api/public/pages/home/sections?lang=en')->assertOk()
            ->assertJsonPath('data.sections.0.items.0.title', 'First')
            ->assertJsonPath('data.sections.0.items.1.title', 'Second')
            ->assertJsonMissing(['title' => 'Hidden']);
    }
}
