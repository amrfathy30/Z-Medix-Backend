<?php

namespace Tests\Feature\Cms\PublicApi;

use App\Enums\ContentStatus;
use App\Models\Page;
use App\Models\PageSection;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PageSectionTest extends TestCase
{
    use RefreshDatabase;

    private function url(string $pageKey): string
    {
        return "/api/public/pages/{$pageKey}/sections";
    }

    private function publishedPage(string $key): Page
    {
        return Page::factory()->create(['key' => $key, 'status' => ContentStatus::Published]);
    }

    public function test_home_sections_endpoint_returns_200(): void
    {
        $page = $this->publishedPage('home');
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);

        $this->getJson($this->url('home'))->assertOk()->assertJsonPath('success', true);
    }

    public function test_about_us_sections_endpoint_returns_200(): void
    {
        $page = $this->publishedPage('about-us');
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);

        $this->getJson($this->url('about-us'))->assertOk();
    }

    public function test_services_sections_endpoint_returns_200(): void
    {
        $page = $this->publishedPage('services');
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);

        $this->getJson($this->url('services'))->assertOk();
    }

    public function test_privacy_policy_sections_endpoint_returns_200(): void
    {
        $page = $this->publishedPage('privacy-policy');
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'content']);

        $this->getJson($this->url('privacy-policy'))->assertOk();
    }

    public function test_terms_and_conditions_sections_endpoint_returns_200(): void
    {
        $page = $this->publishedPage('terms-and-conditions');
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'content']);

        $this->getJson($this->url('terms-and-conditions'))->assertOk();
    }

    public function test_unknown_page_key_returns_404(): void
    {
        $this->getJson($this->url('does-not-exist'))->assertNotFound();
    }

    public function test_draft_page_returns_404(): void
    {
        $page = Page::factory()->create(['key' => 'draft-page', 'status' => ContentStatus::Draft]);
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);

        $this->getJson($this->url('draft-page'))->assertNotFound();
    }

    public function test_draft_sections_are_not_returned(): void
    {
        $page = $this->publishedPage('home');
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero', 'status' => ContentStatus::Published]);
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'faq', 'status' => ContentStatus::Draft]);

        $response = $this->getJson($this->url('home'))->assertOk();

        $keys = collect($response->json('data.sections'))->pluck('section_key')->all();

        $this->assertContains('hero', $keys);
        $this->assertNotContains('faq', $keys);
    }

    public function test_sections_are_sorted_by_sort_order(): void
    {
        $page = $this->publishedPage('home');
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero', 'sort_order' => 2]);
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'features', 'sort_order' => 1]);
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'faq', 'sort_order' => 3]);

        $response = $this->getJson($this->url('home'))->assertOk();

        $keys = collect($response->json('data.sections'))->pluck('section_key')->all();

        $this->assertSame(['features', 'hero', 'faq'], $keys);
    }

    public function test_media_urls_are_included_when_media_exists(): void
    {
        Storage::fake('public');

        $page = $this->publishedPage('home');
        $section = PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'hero']);
        app(MediaUploadService::class)->upload($section, UploadedFile::fake()->image('bg.jpg'), 'background');
        app(MediaUploadService::class)->uploadMultiple($section, [
            UploadedFile::fake()->image('g1.jpg'),
            UploadedFile::fake()->image('g2.jpg'),
        ], 'gallery');

        $response = $this->getJson($this->url('home'))->assertOk();

        $section = $response->json('data.sections.0');

        $this->assertNotNull($section['media']['background']);
        $this->assertIsString($section['media']['background']['url']);
        $this->assertNull($section['media']['image']);
        $this->assertCount(2, $section['media']['gallery']);
    }

    public function test_localized_title_subtitle_body_with_lang_ar(): void
    {
        $page = $this->publishedPage('home');
        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'hero',
            'data' => [
                'title' => ['en' => 'Welcome', 'ar' => 'مرحبًا'],
                'subtitle' => ['en' => 'Subtitle EN', 'ar' => 'العنوان الفرعي'],
                'body' => ['en' => 'Body EN', 'ar' => 'المحتوى بالعربية'],
            ],
        ]);

        $response = $this->getJson($this->url('home').'?lang=ar')->assertOk();

        $section = $response->json('data.sections.0');

        $this->assertSame('مرحبًا', $section['title']);
        $this->assertSame('العنوان الفرعي', $section['subtitle']);
        $this->assertSame('المحتوى بالعربية', $section['body']);
    }

    public function test_localized_title_subtitle_body_with_lang_en(): void
    {
        $page = $this->publishedPage('home');
        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'hero',
            'data' => [
                'title' => ['en' => 'Welcome', 'ar' => 'مرحبًا'],
                'subtitle' => ['en' => 'Subtitle EN', 'ar' => 'العنوان الفرعي'],
                'body' => ['en' => 'Body EN', 'ar' => 'المحتوى بالعربية'],
            ],
        ]);

        $response = $this->getJson($this->url('home').'?lang=en')->assertOk();

        $section = $response->json('data.sections.0');

        $this->assertSame('Welcome', $section['title']);
        $this->assertSame('Subtitle EN', $section['subtitle']);
        $this->assertSame('Body EN', $section['body']);
    }

    public function test_nested_data_json_is_localized(): void
    {
        $page = $this->publishedPage('services');
        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'benefits',
            'data' => [
                'items' => [
                    ['en' => 'Flexible schedule', 'ar' => 'جدول مرن'],
                    ['en' => 'Higher income', 'ar' => 'دخل أعلى'],
                ],
                'cards' => [
                    ['title' => ['en' => 'Private & Secure', 'ar' => 'خصوصية وأمان'], 'description' => ['en' => 'Desc EN', 'ar' => 'وصف عربي']],
                ],
            ],
        ]);

        $response = $this->getJson($this->url('services').'?lang=ar')->assertOk();

        $data = $response->json('data.sections.0.data');

        $this->assertSame('جدول مرن', $data['items'][0]);
        $this->assertSame('دخل أعلى', $data['items'][1]);
        $this->assertSame('خصوصية وأمان', $data['cards'][0]['title']);
        $this->assertSame('وصف عربي', $data['cards'][0]['description']);
    }

    public function test_fallback_works_if_current_locale_value_missing(): void
    {
        $page = $this->publishedPage('services');
        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'hero',
            'data' => [
                'title' => ['en' => 'English Only Title'],
                'items' => [
                    ['en' => 'English Only Item'],
                ],
            ],
        ]);

        $response = $this->getJson($this->url('services').'?lang=ar')->assertOk();

        $section = $response->json('data.sections.0');

        $this->assertSame('English Only Title', $section['title']);
        $this->assertSame('English Only Item', $section['data']['items'][0]);
    }

    public function test_legacy_flat_button_text_and_url_are_extracted_as_fallback(): void
    {
        $page = $this->publishedPage('home');
        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'hero',
            'data' => [
                'button_text' => ['en' => 'Get Started', 'ar' => 'ابدأ الآن'],
                'button_url' => 'https://example.test/start',
            ],
        ]);

        $response = $this->getJson($this->url('home').'?lang=ar')->assertOk();

        $section = $response->json('data.sections.0');

        $this->assertSame('ابدأ الآن', $section['button_text']);
        $this->assertSame('https://example.test/start', $section['button_url']);
        $this->assertArrayNotHasKey('button_text', $section['data']);
        $this->assertArrayNotHasKey('button_url', $section['data']);
    }

    public function test_canonical_cta_shape_is_extracted_into_button_text_and_url(): void
    {
        $page = $this->publishedPage('home');
        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'hero',
            'data' => [
                'cta' => [
                    'text' => ['en' => 'Get Started', 'ar' => 'ابدأ الآن'],
                    'url' => 'https://example.test/start',
                ],
            ],
        ]);

        $response = $this->getJson($this->url('home').'?lang=ar')->assertOk();

        $section = $response->json('data.sections.0');

        $this->assertSame('ابدأ الآن', $section['button_text']);
        $this->assertSame('https://example.test/start', $section['button_url']);
    }

    public function test_title_subtitle_body_are_read_from_data(): void
    {
        $page = $this->publishedPage('home');
        PageSection::factory()->create([
            'page_id' => $page->id,
            'section_key' => 'hero',
            'data' => [
                'title' => ['en' => 'Data Title', 'ar' => 'عنوان البيانات'],
                'subtitle' => ['en' => 'Data Subtitle', 'ar' => 'عنوان فرعي للبيانات'],
                'body' => ['en' => 'Data Body', 'ar' => 'محتوى البيانات'],
            ],
        ]);

        $response = $this->getJson($this->url('home').'?lang=ar')->assertOk();

        $section = $response->json('data.sections.0');

        $this->assertSame('عنوان البيانات', $section['title']);
        $this->assertSame('عنوان فرعي للبيانات', $section['subtitle']);
        $this->assertSame('محتوى البيانات', $section['body']);
    }

    public function test_no_n_plus_one_query_problem(): void
    {
        $page = $this->publishedPage('home');
        PageSection::factory()->count(10)->create(['page_id' => $page->id]);

        DB::enableQueryLog();

        $this->getJson($this->url('home'))->assertOk();

        $queryCount = count(DB::getQueryLog());

        $this->assertLessThan(10, $queryCount);
    }
}
