<?php

namespace Tests\Feature\Cms\PublicApi;

use App\Enums\ContentStatus;
use App\Models\Blog;
use App\Models\BlogCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogTest extends TestCase
{
    use RefreshDatabase;

    private string $categoriesUrl = '/api/public/blog-categories';

    private string $blogsUrl = '/api/public/blogs';

    private function blogUrl(string $slug): string
    {
        return "/api/public/blogs/{$slug}";
    }

    // ── Categories ────────────────────────────────────────────────────────────

    public function test_blog_categories_endpoint_returns_published_categories(): void
    {
        BlogCategory::factory()->count(2)->create(['status' => ContentStatus::Published]);
        BlogCategory::factory()->create(['status' => ContentStatus::Archived]);

        $response = $this->getJson($this->categoriesUrl);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_blog_categories_returns_localized_name(): void
    {
        BlogCategory::factory()->create([
            'name' => ['en' => 'Announcements', 'ar' => 'إعلانات'],
            'status' => ContentStatus::Published,
        ]);

        $arResponse = $this->getJson($this->categoriesUrl.'?lang=ar');
        $enResponse = $this->getJson($this->categoriesUrl.'?lang=en');

        $arResponse->assertJsonPath('data.0.name', 'إعلانات');
        $enResponse->assertJsonPath('data.0.name', 'Announcements');
    }

    // ── Blog List ─────────────────────────────────────────────────────────────

    public function test_blogs_endpoint_returns_paginated_published_blogs(): void
    {
        Blog::factory()->count(3)->create(['status' => ContentStatus::Published, 'published_at' => now()->subDay()]);
        Blog::factory()->draft()->create();

        $response = $this->getJson($this->blogsUrl);

        $response->assertOk()
            ->assertJsonStructure(['success', 'data', 'meta' => ['current_page', 'last_page', 'per_page', 'total'], 'links']);

        $this->assertSame(3, $response->json('meta.total'));
    }

    public function test_blogs_endpoint_hides_draft_and_archived(): void
    {
        Blog::factory()->draft()->create();
        Blog::factory()->archived()->create();

        $response = $this->getJson($this->blogsUrl);

        $response->assertOk();
        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_blogs_endpoint_filters_by_category_slug(): void
    {
        $targetCategory = BlogCategory::factory()->create(['slug' => 'announcements', 'status' => ContentStatus::Published]);
        $otherCategory = BlogCategory::factory()->create(['slug' => 'general', 'status' => ContentStatus::Published]);

        Blog::factory()->for($targetCategory, 'category')->create(['status' => ContentStatus::Published, 'published_at' => now()->subDay()]);
        Blog::factory()->for($targetCategory, 'category')->create(['status' => ContentStatus::Published, 'published_at' => now()->subDay()]);
        Blog::factory()->for($otherCategory, 'category')->create(['status' => ContentStatus::Published, 'published_at' => now()->subDay()]);

        $response = $this->getJson($this->blogsUrl.'?category=announcements');

        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_blogs_endpoint_searches_by_title(): void
    {
        Blog::factory()->create([
            'title' => ['en' => 'Introduction to the Platform', 'ar' => 'مقدمة في المنصة'],
            'status' => ContentStatus::Published,
            'published_at' => now()->subDay(),
        ]);
        Blog::factory()->create([
            'title' => ['en' => 'Product Roadmap Overview', 'ar' => 'نظرة عامة على خارطة الطريق'],
            'status' => ContentStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->getJson($this->blogsUrl.'?search=Platform&lang=en');

        $response->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertStringContainsString('Platform', $response->json('data.0.title'));
    }

    public function test_blogs_per_page_is_capped_at_50(): void
    {
        Blog::factory()->count(5)->create(['status' => ContentStatus::Published, 'published_at' => now()->subDay()]);

        $response = $this->getJson($this->blogsUrl.'?per_page=200');

        $response->assertOk();
        $this->assertSame(50, $response->json('meta.per_page'));
    }

    public function test_blogs_excludes_future_published_at(): void
    {
        Blog::factory()->create(['status' => ContentStatus::Published, 'published_at' => now()->addDays(2)]);
        Blog::factory()->create(['status' => ContentStatus::Published, 'published_at' => now()->subDay()]);

        $response = $this->getJson($this->blogsUrl);

        $response->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_blogs_localization_works(): void
    {
        Blog::factory()->create([
            'title' => ['en' => 'English Title', 'ar' => 'العنوان العربي'],
            'status' => ContentStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        $arResponse = $this->getJson($this->blogsUrl.'?lang=ar');
        $enResponse = $this->getJson($this->blogsUrl.'?lang=en');

        $arResponse->assertJsonPath('data.0.title', 'العنوان العربي');
        $enResponse->assertJsonPath('data.0.title', 'English Title');
    }

    // ── Single Blog ───────────────────────────────────────────────────────────

    public function test_single_blog_can_be_fetched_by_slug(): void
    {
        $blog = Blog::factory()->create([
            'slug' => 'my-article',
            'status' => ContentStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->getJson($this->blogUrl('my-article'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'my-article');
    }

    public function test_single_draft_blog_returns_404(): void
    {
        Blog::factory()->draft()->create(['slug' => 'draft-blog']);

        $this->getJson($this->blogUrl('draft-blog'))->assertNotFound();
    }

    public function test_single_archived_blog_returns_404(): void
    {
        Blog::factory()->archived()->create(['slug' => 'archived-blog']);

        $this->getJson($this->blogUrl('archived-blog'))->assertNotFound();
    }

    public function test_single_blog_includes_category(): void
    {
        $category = BlogCategory::factory()->create(['slug' => 'legal-news', 'status' => ContentStatus::Published]);
        Blog::factory()->for($category, 'category')->create([
            'slug' => 'my-article',
            'status' => ContentStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->getJson($this->blogUrl('my-article'));

        $response->assertOk()
            ->assertJsonPath('data.category.slug', 'legal-news');
    }
}
