<?php

namespace Tests\Feature\Cms\PublicApi;

use App\Models\Page;
use App\Models\PageSection;
use App\Services\Media\MediaUploadService;
use Database\Seeders\PageSectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The landing page as the frontend consumes it: GET /api/public/pages/home/sections. */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PageSectionSeeder::class);
    }

    /** @return Collection<string, array<string, mixed>> Sections keyed by section_key. */
    private function sections(string $lang = 'en'): Collection
    {
        return collect($this->getJson("/api/public/pages/home/sections?lang={$lang}")
            ->assertOk()
            ->json('data.sections'))
            ->keyBy('section_key');
    }

    private function section(string $sectionKey): PageSection
    {
        $page = Page::query()->where('key', 'home')->firstOrFail();

        return PageSection::query()->where('page_id', $page->id)->where('section_key', $sectionKey)->firstOrFail();
    }

    public function test_home_returns_the_five_landing_sections_in_design_order(): void
    {
        $this->assertSame(
            ['hero', 'features', 'plans', 'ai_assistant', 'on_mobile'],
            $this->sections()->keys()->all(),
        );
    }

    public function test_text_is_localised_per_request_language(): void
    {
        $en = $this->sections('en');
        $ar = $this->sections('ar');

        $this->assertSame('Manage Your Health Anytime, Anywhere.', $en['hero']['title']);
        $this->assertSame('أدر صحتك في أي وقت ومن أي مكان.', $ar['hero']['title']);
        $this->assertNotEmpty($en['hero']['data']['description']);
        $this->assertNotSame($en['hero']['data']['description'], $ar['hero']['data']['description']);

        $this->assertSame('The AI Study Partner Every Med Student Needs', $en['on_mobile']['title']);
        $this->assertSame('Meet Your AI Study Assistant', $en['ai_assistant']['title']);
        $this->assertSame('AI-Powered Learning', $en['ai_assistant']['subtitle']);
    }

    public function test_features_plans_and_highlights_have_the_designed_item_counts(): void
    {
        $sections = $this->sections();

        $this->assertCount(6, $sections['features']['data']['features']);
        $this->assertCount(3, $sections['ai_assistant']['data']['highlights']);

        $plans = collect($sections['plans']['data']['plans']);
        $this->assertCount(6, $plans);
        $this->assertCount(3, $plans->where('billing_type', 'monthly'));
        $this->assertCount(3, $plans->where('billing_type', 'yearly'));
    }

    public function test_plan_rows_carry_every_field_the_frontend_needs_localised(): void
    {
        $plan = $this->sections('ar')['plans']['data']['plans'][0];

        // A JSON column does not preserve object key order, so compare the set of keys.
        $this->assertEqualsCanonicalizing(
            ['title', 'price', 'discount', 'billing_type', 'features', 'background_color', 'icon', 'icon_color'],
            array_keys($plan),
        );
        $this->assertSame('الباقة الأساسية', $plan['title']);
        $this->assertSame('حظر الموسيقى الصريحة', $plan['features'][0]['label']);
        $this->assertSame('crown', $plan['icon']);
        $this->assertEquals(5.99, $plan['price']);
    }

    public function test_repeater_icons_are_public_urls_once_uploaded_and_null_before(): void
    {
        $section = $this->section('features');
        $data = $section->data;
        $data['features'][0]['icon'] = 'content/ai-assistant.png';
        $section->update(['data' => $data]);

        $features = $this->sections()['features']['data']['features'];

        $this->assertSame(
            rtrim((string) config('app.url'), '/').'/storage/content/ai-assistant.png',
            $features[0]['icon'],
        );
        $this->assertNull($features[1]['icon']);
    }

    public function test_highlight_icons_are_resolved_the_same_way(): void
    {
        $section = $this->section('ai_assistant');
        $data = $section->data;
        $data['highlights'][2]['icon'] = 'content/quiz.svg';
        $section->update(['data' => $data]);

        $highlights = $this->sections()['ai_assistant']['data']['highlights'];

        $this->assertStringEndsWith('/storage/content/quiz.svg', $highlights[2]['icon']);
        $this->assertNull($highlights[0]['icon']);
    }

    public function test_media_block_exposes_the_new_hero_and_logo_collections_and_a_four_image_gallery(): void
    {
        Storage::fake('public');
        Storage::fake('filament_public');

        $uploads = app(MediaUploadService::class);

        $hero = $this->section('hero');
        $uploads->upload($hero, UploadedFile::fake()->image('hero.png'), 'image');
        $uploads->upload($hero, UploadedFile::fake()->image('hero-small.png'), 'image_small');

        $ai = $this->section('ai_assistant');
        $uploads->upload($ai, UploadedFile::fake()->image('logo.png'), 'logo');
        $uploads->uploadMultiple($ai, array_map(
            fn (int $i): UploadedFile => UploadedFile::fake()->image("screen-{$i}.png"),
            [1, 2, 3, 4],
        ), 'gallery');

        $sections = $this->sections();

        $this->assertIsString($sections['hero']['media']['image']['url']);
        $this->assertIsString($sections['hero']['media']['image_small']['url']);
        $this->assertNull($sections['hero']['media']['logo']);
        $this->assertIsString($sections['ai_assistant']['media']['logo']['url']);
        $this->assertCount(4, $sections['ai_assistant']['media']['gallery']);
        $this->assertNull($sections['on_mobile']['media']['image']);
    }

    public function test_on_mobile_exposes_store_links_under_data(): void
    {
        $section = $this->section('on_mobile');
        $section->update(['data' => array_merge($section->data, [
            'app_store_url' => 'https://apps.apple.com/app/z-medix',
            'google_play_url' => 'https://play.google.com/store/apps/details?id=z.medix',
        ])]);

        $onMobile = $this->sections()['on_mobile'];

        $this->assertSame('https://apps.apple.com/app/z-medix', $onMobile['data']['app_store_url']);
        $this->assertSame('https://play.google.com/store/apps/details?id=z.medix', $onMobile['data']['google_play_url']);
    }

    public function test_placeholder_sections_removed_from_the_definition_are_no_longer_served(): void
    {
        $page = Page::query()->where('key', 'home')->firstOrFail();
        PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'testimonials']);

        $this->assertNotContains('testimonials', $this->sections()->keys()->all());
    }
}
