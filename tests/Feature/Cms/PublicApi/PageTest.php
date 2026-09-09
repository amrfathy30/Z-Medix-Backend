<?php

namespace Tests\Feature\Cms\PublicApi;

use App\Enums\ContentStatus;
use App\Models\Page;
use App\Models\PageSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageTest extends TestCase
{
    use RefreshDatabase;

    private function url(string $key): string
    {
        return "/api/public/pages/{$key}";
    }

    public function test_published_page_can_be_fetched_by_key(): void
    {
        $page = Page::factory()->create([
            'key' => 'about-us',
            'status' => ContentStatus::Published,
        ]);

        $response = $this->getJson($this->url('about-us'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.key', 'about-us');
    }

    public function test_draft_page_returns_404(): void
    {
        Page::factory()->draft()->create(['key' => 'draft-page']);

        $this->getJson($this->url('draft-page'))->assertNotFound();
    }

    public function test_archived_page_returns_404(): void
    {
        Page::factory()->archived()->create(['key' => 'archived-page']);

        $this->getJson($this->url('archived-page'))->assertNotFound();
    }

    public function test_nonexistent_page_returns_404(): void
    {
        $this->getJson($this->url('does-not-exist'))->assertNotFound();
    }

    public function test_privacy_policy_page_is_available(): void
    {
        Page::factory()->create(['key' => 'privacy-policy', 'status' => ContentStatus::Published]);

        $this->getJson($this->url('privacy-policy'))->assertOk();
    }

    public function test_terms_and_conditions_page_is_available(): void
    {
        Page::factory()->create(['key' => 'terms-and-conditions', 'status' => ContentStatus::Published]);

        $this->getJson($this->url('terms-and-conditions'))->assertOk();
    }

    public function test_page_returns_localized_title_and_content_from_section_data(): void
    {
        $page = Page::factory()->create([
            'key' => 'about-us',
            'status' => ContentStatus::Published,
        ]);

        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'content',
            'status' => ContentStatus::Published,
            'data' => [
                'title' => ['en' => 'About Us', 'ar' => 'من نحن'],
                'body' => ['en' => 'English content', 'ar' => 'المحتوى العربي'],
            ],
        ]);

        $arResponse = $this->getJson($this->url('about-us').'?lang=ar');
        $enResponse = $this->getJson($this->url('about-us').'?lang=en');

        $arResponse->assertJsonPath('data.title', 'من نحن')
            ->assertJsonPath('data.content', 'المحتوى العربي');

        $enResponse->assertJsonPath('data.title', 'About Us')
            ->assertJsonPath('data.content', 'English content');
    }

    public function test_page_response_includes_expected_fields(): void
    {
        Page::factory()->create(['key' => 'about-us', 'status' => ContentStatus::Published]);

        $this->getJson($this->url('about-us'))
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['key', 'title', 'content', 'meta_title', 'meta_description', 'banner_url', 'published_at'],
            ])
            ->assertJsonMissingPath('data.slug');
    }

    public function test_response_exposes_key_and_never_slug(): void
    {
        Page::factory()->create(['key' => 'privacy-policy', 'status' => ContentStatus::Published]);

        $this->getJson($this->url('privacy-policy'))
            ->assertOk()
            ->assertJsonPath('data.key', 'privacy-policy')
            ->assertJsonMissingPath('data.slug');
    }

    public function test_page_content_is_read_from_published_content_section(): void
    {
        $page = Page::factory()->create([
            'key' => 'privacy-policy',
            'status' => ContentStatus::Published,
        ]);

        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'content',
            'status' => ContentStatus::Published,
            'data' => [
                'title' => ['en' => 'Section Title', 'ar' => 'عنوان القسم'],
                'body' => ['en' => 'Section Body', 'ar' => 'محتوى القسم'],
                'meta_title' => ['en' => 'Section Meta Title'],
                'meta_description' => ['en' => 'Section Meta Description'],
            ],
        ]);

        $response = $this->getJson($this->url('privacy-policy'))->assertOk();

        $response->assertJsonPath('data.title', 'Section Title')
            ->assertJsonPath('data.content', 'Section Body')
            ->assertJsonPath('data.meta_title', 'Section Meta Title')
            ->assertJsonPath('data.meta_description', 'Section Meta Description');
    }

    public function test_page_content_is_null_when_no_content_section_exists(): void
    {
        $page = Page::factory()->create([
            'key' => 'about-us',
            'status' => ContentStatus::Published,
        ]);

        $response = $this->getJson($this->url('about-us'))->assertOk();

        $response->assertJsonPath('data.title', $page->label)
            ->assertJsonPath('data.content', null)
            ->assertJsonPath('data.meta_title', null)
            ->assertJsonPath('data.meta_description', null);
    }

    public function test_page_content_ignores_draft_content_section(): void
    {
        $page = Page::factory()->create([
            'key' => 'privacy-policy',
            'status' => ContentStatus::Published,
        ]);

        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'content',
            'status' => ContentStatus::Draft,
            'data' => ['title' => ['en' => 'Draft Section Title']],
        ]);

        $this->getJson($this->url('privacy-policy'))
            ->assertOk()
            ->assertJsonPath('data.title', $page->label);
    }
}
