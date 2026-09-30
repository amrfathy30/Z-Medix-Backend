<?php

namespace Tests\Feature\Cms;

use App\Enums\ContentStatus;
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

        $this->assertSame('Manage Your Health Anytime, Anywhere.', $hero->data['title']['en']);
        $this->assertSame('أدر صحتك في أي وقت ومن أي مكان.', $hero->data['title']['ar']);
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

    private function homePage(): Page
    {
        return Page::query()->where('key', 'home')->firstOrFail();
    }

    public function test_seeder_writes_default_home_seo_in_both_languages_and_publishes_the_page(): void
    {
        $this->seed(PageSectionSeeder::class);

        $home = $this->homePage();

        $this->assertSame('Z-MEDIX — AI Study Assistant for Medical Students', $home->getTranslation('meta_title', 'en'));
        $this->assertSame('Z-MEDIX — مساعدك الذكي لدراسة الطب', $home->getTranslation('meta_title', 'ar'));
        $this->assertNotEmpty($home->getTranslation('meta_description', 'en'));
        $this->assertNotEmpty($home->getTranslation('meta_description', 'ar'));
        $this->assertNotNull($home->published_at);
        // Sitemap/hreflang paths must match the real frontend URLs, so they are never guessed.
        $this->assertSame([], $home->getTranslations('public_path'));
        $this->assertNull($home->canonical_url);
    }

    public function test_seeder_fills_empty_seo_on_an_already_seeded_page(): void
    {
        $home = Page::factory()->create(['key' => 'home', 'status' => ContentStatus::Published, 'published_at' => null]);
        $home->forceFill(['meta_title' => null, 'meta_description' => null, 'published_at' => null])->saveQuietly();

        $this->seed(PageSectionSeeder::class);

        $home->refresh();
        $this->assertSame('Z-MEDIX — مساعدك الذكي لدراسة الطب', $home->getTranslation('meta_title', 'ar'));
        $this->assertNotNull($home->published_at);
    }

    public function test_seeder_never_overwrites_admin_edited_seo_or_publish_date(): void
    {
        $this->seed(PageSectionSeeder::class);

        $home = $this->homePage();
        $publishedAt = now()->subYear()->startOfSecond();
        $home->setTranslation('meta_title', 'en', 'Admin SEO Title');
        $home->forceFill(['published_at' => $publishedAt])->saveQuietly();

        $this->seed(PageSectionSeeder::class);

        $home->refresh();
        $this->assertSame('Admin SEO Title', $home->getTranslation('meta_title', 'en'));
        $this->assertTrue($home->published_at->equalTo($publishedAt));
    }

    public function test_seeder_does_not_publish_a_draft_page(): void
    {
        Page::factory()->draft()->create(['key' => 'about-us', 'published_at' => null]);

        $this->seed(PageSectionSeeder::class);

        $this->assertNull(Page::query()->where('key', 'about-us')->firstOrFail()->published_at);
    }

    public function test_home_seo_is_returned_by_the_public_page_api_per_language(): void
    {
        $this->seed(PageSectionSeeder::class);

        $this->getJson('/api/public/pages/home?lang=en')->assertOk()
            ->assertJsonPath('data.meta_title', 'Z-MEDIX — AI Study Assistant for Medical Students')
            ->assertJsonPath('data.meta_description', $this->homePage()->getTranslation('meta_description', 'en'))
            ->assertJsonPath('data.canonical_url', null);

        $this->getJson('/api/public/pages/home?lang=ar')->assertOk()
            ->assertJsonPath('data.meta_title', 'Z-MEDIX — مساعدك الذكي لدراسة الطب');

        $this->assertNotNull($this->getJson('/api/public/pages/home')->json('data.published_at'));
    }
}
