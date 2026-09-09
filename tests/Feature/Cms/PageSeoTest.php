<?php

namespace Tests\Feature\Cms;

use App\Enums\ContentStatus;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('seo.public_base_url', 'https://example.test');
    }

    public function test_page_seo_fields_are_translatable_and_urls_are_locale_aware(): void
    {
        $page = Page::factory()->create([
            'meta_title' => ['en' => 'English', 'ar' => 'عربي'],
            'public_path' => ['en' => '/en/about', 'ar' => '/ar/about'],
        ]);

        $this->assertSame('English', $page->getTranslation('meta_title', 'en'));
        $this->assertSame('https://example.test/en/about', $page->resolvedPublicUrl('en'));
        $this->assertSame([
            'ar' => 'https://example.test/ar/about',
            'en' => 'https://example.test/en/about',
        ], $page->alternateUrls());
    }

    public function test_canonical_override_wins_and_sitemap_scope_excludes_ineligible_pages(): void
    {
        $eligible = Page::factory()->create(['public_path' => ['en' => '/eligible']]);
        $canonical = Page::factory()->create(['canonical_url' => 'https://canonical.test/page']);
        Page::factory()->draft()->create(['public_path' => ['en' => '/draft']]);
        Page::factory()->create(['public_path' => ['en' => '/blocked'], 'is_indexable' => false]);
        Page::factory()->create(['public_path' => ['en' => '/omitted'], 'include_in_sitemap' => false]);

        $this->assertSame('https://canonical.test/page', $canonical->resolvedPublicUrl('en'));
        $this->assertEqualsCanonicalizing(
            [$eligible->id, $canonical->id],
            Page::query()->sitemapEligible()->pluck('id')->all(),
        );
    }

    public function test_published_at_is_stamped_once(): void
    {
        $page = Page::factory()->create(['status' => ContentStatus::Published, 'published_at' => null]);
        $publishedAt = $page->published_at;
        $page->update(['label' => 'Changed']);

        $this->assertNotNull($publishedAt);
        $this->assertTrue($page->fresh()->published_at->equalTo($publishedAt));
    }
}
