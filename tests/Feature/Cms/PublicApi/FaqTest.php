<?php

namespace Tests\Feature\Cms\PublicApi;

use App\Enums\ContentStatus;
use App\Models\Faq;
use App\Models\FaqCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqTest extends TestCase
{
    use RefreshDatabase;

    private string $categoriesUrl = '/api/public/faq-categories';

    private string $faqsUrl = '/api/public/faqs';

    // ── Categories ────────────────────────────────────────────────────────────

    public function test_faq_categories_endpoint_returns_published_categories(): void
    {
        FaqCategory::factory()->count(2)->create(['status' => ContentStatus::Published]);
        FaqCategory::factory()->create(['status' => ContentStatus::Archived]);

        $response = $this->getJson($this->categoriesUrl);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_faq_categories_hides_archived(): void
    {
        FaqCategory::factory()->create(['status' => ContentStatus::Archived]);

        $response = $this->getJson($this->categoriesUrl);

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_faq_categories_localization(): void
    {
        FaqCategory::factory()->create([
            'name' => ['en' => 'General', 'ar' => 'عام'],
            'status' => ContentStatus::Published,
        ]);

        $arResponse = $this->getJson($this->categoriesUrl.'?lang=ar');
        $enResponse = $this->getJson($this->categoriesUrl.'?lang=en');

        $arResponse->assertJsonPath('data.0.name', 'عام');
        $enResponse->assertJsonPath('data.0.name', 'General');
    }

    // ── FAQs (Grouped) ────────────────────────────────────────────────────────

    public function test_faqs_endpoint_returns_grouped_data(): void
    {
        $cat1 = FaqCategory::factory()->create(['status' => ContentStatus::Published, 'sort_order' => 1]);
        $cat2 = FaqCategory::factory()->create(['status' => ContentStatus::Published, 'sort_order' => 2]);
        Faq::factory()->for($cat1, 'category')->create(['status' => ContentStatus::Published]);
        Faq::factory()->for($cat2, 'category')->create(['status' => ContentStatus::Published]);

        $response = $this->getJson($this->faqsUrl);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data);
        $this->assertArrayHasKey('faqs', $data[0]);
    }

    public function test_faqs_endpoint_category_filter_works(): void
    {
        $targetCat = FaqCategory::factory()->create(['slug' => 'booking', 'status' => ContentStatus::Published]);
        $otherCat = FaqCategory::factory()->create(['slug' => 'general', 'status' => ContentStatus::Published]);
        Faq::factory()->for($targetCat, 'category')->create(['status' => ContentStatus::Published]);
        Faq::factory()->for($otherCat, 'category')->create(['status' => ContentStatus::Published]);

        $response = $this->getJson($this->faqsUrl.'?category=booking');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('booking', $response->json('data.0.slug'));
    }

    public function test_faqs_hides_archived_faq_categories(): void
    {
        FaqCategory::factory()->create(['status' => ContentStatus::Archived]);

        $response = $this->getJson($this->faqsUrl);

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_faqs_hides_archived_faqs_within_category(): void
    {
        $category = FaqCategory::factory()->create(['status' => ContentStatus::Published]);
        Faq::factory()->for($category, 'category')->archived()->create();
        Faq::factory()->for($category, 'category')->create(['status' => ContentStatus::Published]);

        $response = $this->getJson($this->faqsUrl);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.0.faqs'));
    }

    public function test_faqs_localization(): void
    {
        $category = FaqCategory::factory()->create([
            'name' => ['en' => 'General', 'ar' => 'عام'],
            'status' => ContentStatus::Published,
        ]);
        Faq::factory()->for($category, 'category')->create([
            'question' => ['en' => 'What is this?', 'ar' => 'ما هذا؟'],
            'status' => ContentStatus::Published,
        ]);

        $arResponse = $this->getJson($this->faqsUrl.'?lang=ar');
        $enResponse = $this->getJson($this->faqsUrl.'?lang=en');

        $arResponse->assertJsonPath('data.0.name', 'عام')
            ->assertJsonPath('data.0.faqs.0.question', 'ما هذا؟');

        $enResponse->assertJsonPath('data.0.name', 'General')
            ->assertJsonPath('data.0.faqs.0.question', 'What is this?');
    }
}
