<?php

namespace Tests\Feature\Cms\PublicApi;

use App\Enums\ContentStatus;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\FaqCategory;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/home';

    public function test_home_endpoint_returns_expected_structure(): void
    {
        Setting::factory()->inGroup('general')->create(['key' => 'site_name', 'value' => 'Starter Platform']);
        $category = BlogCategory::factory()->create(['status' => ContentStatus::Published]);
        Blog::factory()->for($category, 'category')->create(['status' => ContentStatus::Published, 'published_at' => now()->subDay()]);
        $faqCategory = FaqCategory::factory()->create(['status' => ContentStatus::Published]);

        $response = $this->getJson($this->url);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'settings',
                    'latest_blogs',
                    'faqs',
                ],
            ])
            ->assertJsonPath('success', true);
    }

    public function test_home_returns_only_public_settings(): void
    {
        Setting::factory()->create(['key' => 'public_key', 'is_public' => true]);
        Setting::factory()->private()->create(['key' => 'private_key']);

        $response = $this->getJson($this->url);

        $response->assertOk();

        $allKeys = collect($response->json('data.settings'))->flatten(1)->pluck('key')->toArray();
        $this->assertContains('public_key', $allKeys);
        $this->assertNotContains('private_key', $allKeys);
    }

    public function test_home_returns_max_six_latest_blogs(): void
    {
        $category = BlogCategory::factory()->create(['status' => ContentStatus::Published]);
        Blog::factory()->count(8)->for($category, 'category')->create([
            'status' => ContentStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->getJson($this->url);

        $response->assertOk();
        $this->assertCount(6, $response->json('data.latest_blogs'));
    }

    public function test_home_excludes_draft_and_archived_blogs(): void
    {
        $category = BlogCategory::factory()->create(['status' => ContentStatus::Published]);
        Blog::factory()->for($category, 'category')->draft()->create();
        Blog::factory()->for($category, 'category')->archived()->create();

        $response = $this->getJson($this->url);

        $response->assertOk();
        $this->assertCount(0, $response->json('data.latest_blogs'));
    }

    public function test_home_respects_locale_via_query_param(): void
    {
        FaqCategory::factory()->create([
            'name' => ['en' => 'General', 'ar' => 'عام'],
            'status' => ContentStatus::Published,
        ]);

        $arResponse = $this->getJson($this->url.'?lang=ar');
        $enResponse = $this->getJson($this->url.'?lang=en');

        $arResponse->assertOk();
        $enResponse->assertOk();

        $arName = $arResponse->json('data.faqs.0.name');
        $enName = $enResponse->json('data.faqs.0.name');

        $this->assertSame('عام', $arName);
        $this->assertSame('General', $enName);
    }
}
