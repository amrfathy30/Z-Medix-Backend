<?php

namespace Tests\Feature\Cms;

use App\Models\Page;
use App\Models\PageSection;
use Database\Seeders\PageSectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearLegacyPlanIconKeysMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_nulls_icon_keys_but_keeps_uploaded_file_paths(): void
    {
        $this->seed(PageSectionSeeder::class);

        $page = Page::query()->where('key', 'home')->firstOrFail();
        $section = PageSection::query()->where('page_id', $page->id)->where('section_key', 'plans')->firstOrFail();

        $data = $section->data;
        $data['plans'][0]['icon'] = 'crown';
        $data['plans'][1]['icon'] = 'content/crown.svg';
        $section->update(['data' => $data]);

        (require database_path('migrations/2026_10_01_120000_clear_legacy_icon_keys_from_home_plans.php'))->up();

        $plans = $section->refresh()->data['plans'];

        $this->assertNull($plans[0]['icon']);
        $this->assertSame('content/crown.svg', $plans[1]['icon']);
        $this->assertSame('الباقة المميزة', $plans[1]['title']['ar']);
    }
}
