<?php

namespace Tests\Feature\Cms;

use App\Models\Page;
use App\Models\PageSection;
use Database\Seeders\PageSectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageSectionSeederOverwriteTest extends TestCase
{
    use RefreshDatabase;

    private function homeHero(): PageSection
    {
        $page = Page::query()->where('key', 'home')->firstOrFail();

        return PageSection::query()->where('page_id', $page->id)->where('section_key', 'hero')->firstOrFail();
    }

    public function test_seeder_creates_default_data_for_missing_sections(): void
    {
        $this->seed(PageSectionSeeder::class);

        $hero = $this->homeHero();

        $this->assertSame('Welcome', $hero->data['title']['en']);
    }

    public function test_seeder_does_not_overwrite_admin_edited_data(): void
    {
        $this->seed(PageSectionSeeder::class);

        $hero = $this->homeHero();
        $hero->data = ['title' => ['en' => 'Admin Edited Title', 'ar' => 'عنوان محرر'], 'primary_cta_text' => ['en' => 'Admin CTA', 'ar' => 'زر المسؤول']];
        $hero->save();

        $this->seed(PageSectionSeeder::class);

        $hero->refresh();

        $this->assertSame('Admin Edited Title', $hero->data['title']['en']);
        $this->assertSame('Admin CTA', $hero->data['primary_cta_text']['en']);
    }

    public function test_seeder_refreshes_structural_sort_order_without_touching_content(): void
    {
        $this->seed(PageSectionSeeder::class);

        $hero = $this->homeHero();
        $hero->data = array_merge($hero->data, ['title' => ['en' => 'Still Mine', 'ar' => 'لا يزال لي']]);
        $hero->save();

        $this->seed(PageSectionSeeder::class);

        $hero->refresh();

        $this->assertSame(1, $hero->sort_order);
        $this->assertSame('Still Mine', $hero->data['title']['en']);
    }

    public function test_seeder_is_idempotent_on_section_count(): void
    {
        $this->seed(PageSectionSeeder::class);
        $countAfterFirstRun = PageSection::query()->count();

        $this->seed(PageSectionSeeder::class);
        $countAfterSecondRun = PageSection::query()->count();

        $this->assertSame($countAfterFirstRun, $countAfterSecondRun);
    }
}
