<?php

namespace Tests\Feature\Cms;

use App\Enums\ContentStatus;
use App\Filament\Pages\SettingsPage;
use App\Filament\Pages\WebsiteContentPage;
use App\Filament\Pages\WebsiteContentPageSettingsPage;
use App\Filament\Resources\PageSectionResource\Pages\EditPageSection;
use App\Models\Admin;
use App\Models\BlogCategory;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Setting;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use Database\Seeders\CmsSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PageSectionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Runs against an explicitly opted-in disposable MySQL installation, never the project database. */
class FreshCmsInstallationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CMS_FRESH_AUDIT') !== '1') {
            $this->markTestSkipped('Requires a newly provisioned, disposable MySQL audit database.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', config('database.default'));
        $this->assertStringStartsWith('/tmp/zmedix-cms-audit.', config('database.connections.mysql.unix_socket'));
        $target = DB::selectOne('SELECT DATABASE() AS db, @@datadir AS directory, @@skip_networking AS isolated');
        $this->assertSame('zmedix_cms_audit', $target->db);
        $this->assertStringStartsWith('/tmp/zmedix-cms-audit.', $target->directory);
        $this->assertSame(1, (int) $target->isolated);
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        Storage::fake('filament_public');
        $this->actingAs(Admin::where('type', 'super_admin')->firstOrFail(), 'admin');
    }

    private function section(string $pageKey, string $sectionKey): PageSection
    {
        return PageSection::forPageKey($pageKey)->where('section_key', $sectionKey)->firstOrFail();
    }

    public function test_fresh_install_has_the_exact_defined_pages_sections_and_english_forms(): void
    {
        $definitions = app(ContentDefinitionRegistry::class)->allPages();
        $this->assertSame(count($definitions), Page::count());
        $this->assertSame(250, DB::table('countries')->count());
        $this->assertSame(3, DB::table('plans')->count());
        $this->assertSame(6, Faq::count());
        $cards = collect(Livewire::test(WebsiteContentPage::class)->instance()->getCards())->keyBy('key');
        $sectionCount = 0;
        foreach ($definitions as $definition) {
            $this->assertArrayHasKey($definition->key, $cards);
            $expected = array_map(fn ($section): string => $section->sectionKey, $definition->orderedSections());
            $this->assertSame($expected, PageSection::forPageKey($definition->key)->orderBy('sort_order')->pluck('section_key')->all());
            foreach ($definition->orderedSections() as $sectionDefinition) {
                $sectionCount++;
                $section = $this->section($definition->key, $sectionDefinition->sectionKey);
                $editor = Livewire::test(EditPageSection::class, ['record' => $section->id])->assertOk();
                $names = [];
                foreach ($editor->instance()->form->getFlatFields(withHidden: true) as $field) {
                    $this->assertDoesNotMatchRegularExpression('/(?:^|\\.)ar(?:\\.|$)/', $field->getName());
                    $this->assertDoesNotMatchRegularExpression('/\\((?:English|EN)\\)/', (string) $field->getLabel());
                    $names[] = $field->getStatePath();
                }
                $this->assertCount(count(array_unique($names)), $names);
            }
        }
        $this->assertSame($sectionCount, PageSection::count());
        Livewire::test(EditPageSection::class, ['record' => $this->section('home', 'hero')->id])
            ->assertFormFieldExists('image')->assertFormFieldExists('image_small');
    }

    public function test_seeded_benefits_load_edit_and_keep_the_public_string_list_contract(): void
    {
        $section = $this->section('services', 'benefits');
        $before = $section->data;
        $editor = Livewire::test(EditPageSection::class, ['record' => $section->id]);
        $rows = array_values($editor->get('data.data.items'));
        $this->assertSame('Default placeholder benefit one', $rows[0]['en']);
        $rows[0]['en'] = 'A revised English benefit';
        $editor->fillForm(['data.items' => $rows])->call('save')->assertHasNoFormErrors();
        $this->assertSame('A revised English benefit', $section->refresh()->data['items'][0]['en']);
        $this->assertArrayNotHasKey('label', $section->data['items'][0]);
        $this->assertSame($before['items'][0]['ar'], $section->data['items'][0]['ar']);
        $response = $this->getJson('/api/public/pages/services/sections?lang=en')->assertOk();
        $benefits = collect($response->json('data.sections'))->firstWhere('section_key', 'benefits');
        $this->assertSame('A revised English benefit', $benefits['data']['items'][0]);
        $this->assertIsString($benefits['data']['items'][1]);
    }

    public function test_full_deployment_seeding_preserves_edited_content_status_and_counts(): void
    {
        $setting = Setting::where('key', 'site_name')->firstOrFail();
        $setting->update(['value' => 'Administrator Brand']);
        $category = BlogCategory::where('slug', 'announcements')->firstOrFail();
        $category->update(['name' => ['en' => 'Edited Category'], 'status' => ContentStatus::Draft]);
        $faqCategory = FaqCategory::where('slug', 'general')->firstOrFail();
        $faqCategory->update(['name' => ['en' => 'Edited FAQs'], 'status' => ContentStatus::Archived]);
        $faq = $faqCategory->faqs()->orderBy('sort_order')->firstOrFail();
        $faq->update(['question' => ['en' => 'Edited question'], 'answer' => ['en' => 'Edited answer'], 'sort_order' => 99, 'status' => ContentStatus::Draft]);
        $hero = $this->section('home', 'hero');
        $data = $hero->data;
        $data['title']['en'] = 'Admin Hero';
        $hero->update(['data' => $data, 'status' => ContentStatus::Draft]);
        $counts = [Page::count(), PageSection::count(), BlogCategory::count(), FaqCategory::count(), Faq::count(), Setting::count()];
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($counts, [Page::count(), PageSection::count(), BlogCategory::count(), FaqCategory::count(), Faq::count(), Setting::count()]);
        $this->assertSame('Administrator Brand', $setting->fresh()->value);
        $this->assertSame('Edited Category', $category->fresh()->getTranslation('name', 'en'));
        $this->assertSame(ContentStatus::Draft, $category->fresh()->status);
        $this->assertSame(ContentStatus::Archived, $faqCategory->fresh()->status);
        $this->assertSame('Edited question', $faq->fresh()->getTranslation('question', 'en'));
        $this->assertSame(99, $faq->fresh()->sort_order);
        $this->assertSame(ContentStatus::Draft, $faq->fresh()->status);
        $this->assertSame('Admin Hero', $hero->fresh()->data['title']['en']);
        $this->assertSame(ContentStatus::Draft, $hero->fresh()->status);
    }

    public function test_rerunning_seeders_does_not_recreate_soft_deleted_records(): void
    {
        BlogCategory::where('slug', 'updates')->firstOrFail()->delete();
        $this->section('home', 'hero')->delete();
        Page::where('key', 'contact-us')->firstOrFail()->delete();
        $before = [BlogCategory::withTrashed()->count(), Page::withTrashed()->count(), PageSection::withTrashed()->count()];
        $this->seed([CmsSeeder::class, PageSectionSeeder::class]);
        $this->assertSame($before, [BlogCategory::withTrashed()->count(), Page::withTrashed()->count(), PageSection::withTrashed()->count()]);
        $this->assertNull(BlogCategory::where('slug', 'updates')->first());
        $this->assertNull(Page::where('key', 'contact-us')->first());
        $this->assertFalse(PageSection::forPageKey('home')->where('section_key', 'hero')->exists());
    }

    public function test_legal_content_is_editable_and_subscription_policy_publishes_on_save(): void
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('content_manager');
        $this->actingAs($admin, 'admin');
        foreach (['privacy-policy', 'terms-and-conditions', 'subscription-policy'] as $key) {
            $section = $this->section($key, 'content');
            $editor = Livewire::test(EditPageSection::class, ['record' => $section->id])->assertOk()
                ->assertFormFieldDoesNotExist('data.body.ar')
                ->fillForm(['data.body.en' => '<p>Verified '.$key.'</p>'])->call('save')->assertHasNoFormErrors();
            $this->assertSame(ContentStatus::Published, $section->fresh()->status);
            $this->getJson('/api/public/pages/'.$key.'?lang=en')->assertOk()->assertJsonPath('data.content', '<p>Verified '.$key.'</p>');
        }
        $admin->roles->first()->revokePermissionTo('content.update');
        $editor->call('save')->assertForbidden();
    }

    public function test_seeded_seo_and_footer_edits_survive_reseeding(): void
    {
        $page = Page::where('key', 'home')->firstOrFail();
        Livewire::test(WebsiteContentPageSettingsPage::class, ['pageKey' => 'home'])
            ->assertFormFieldDoesNotExist('meta_title.ar')
            ->fillForm(['meta_title.en' => '', 'meta_description.en' => 'Edited SEO'])
            ->call('save')->assertHasNoFormErrors();
        $setting = Setting::where('key', 'footer_description_1')->firstOrFail();
        Livewire::test(SettingsPage::class)
            ->assertFormFieldDoesNotExist('setting_'.$setting->id.'.ar')
            ->fillForm(['setting_'.$setting->id.'.en' => 'Edited footer'])
            ->call('save')->assertHasNoFormErrors();
        $this->seed([CmsSeeder::class, PageSectionSeeder::class]);
        $this->assertSame('', $page->fresh()->getTranslation('meta_title', 'en', false));
        $this->assertSame('Edited SEO', $page->fresh()->getTranslation('meta_description', 'en', false));
        $this->assertSame('Edited footer', json_decode($setting->fresh()->value, true)['en']);
        $response = $this->getJson('/api/public/settings?lang=en')->assertOk();
        $footer = collect($response->json('data.footer'))->firstWhere('key', 'footer_description_1');
        $this->assertSame('Edited footer', $footer['value']);
    }

    public function test_seeded_hero_nested_repeaters_and_ctas_save_and_reload_through_public_api(): void
    {
        $hero = $this->section('home', 'hero');
        Livewire::test(EditPageSection::class, ['record' => $hero->id])
            ->assertFormSet(['data.title.en' => 'Manage Your Health Anytime, Anywhere.'])
            ->fillForm(['data.title.en' => 'Fresh MySQL hero'])->call('save')->assertHasNoFormErrors();
        $plans = $this->section('home', 'plans');
        $editor = Livewire::test(EditPageSection::class, ['record' => $plans->id]);
        $rows = array_reverse(array_values($editor->get('data.data.plans')));
        $rows[0]['features'] = array_reverse(array_values($rows[0]['features']));
        $rows[0]['features'][0]['label']['en'] = 'Fresh nested feature';
        array_pop($rows);
        $editor->fillForm(['data.plans' => $rows])->call('save')->assertHasNoFormErrors();
        $editor->call('save')->assertHasNoFormErrors();
        $this->assertCount(5, $plans->fresh()->data['plans']);
        $response = $this->getJson('/api/public/pages/home/sections?lang=en')->assertOk();
        $sections = collect($response->json('data.sections'))->keyBy('section_key');
        $this->assertSame('Fresh MySQL hero', $sections['hero']['title']);
        $this->assertSame('Fresh nested feature', $sections['plans']['data']['plans'][0]['features'][0]['label']);
        $this->assertIsNumeric($sections['plans']['data']['plans'][0]['price']);
        $cta = $this->section('services', 'cta');
        Livewire::test(EditPageSection::class, ['record' => $cta->id])
            ->fillForm(['data.cta.text.en' => 'Join us', 'data.cta.url' => 'https://example.test/join'])
            ->call('save')->assertHasNoFormErrors();
        $response = $this->getJson('/api/public/pages/services/sections?lang=en')->assertOk();
        $data = collect($response->json('data.sections'))->firstWhere('section_key', 'cta');
        $this->assertSame('Join us', $data['button_text']);
        $this->assertSame('https://example.test/join', $data['button_url']);
        $this->assertSame('Join us', $cta->fresh()->data['cta']['text']['en']);
    }

    public function test_fresh_defaults_describe_english_support_and_recover_missing_policy_without_publishing_it(): void
    {
        $faq = Faq::where('question->en', 'Is the platform available in multiple languages?')->firstOrFail();
        $this->assertSame('The platform currently supports English.', $faq->getTranslation('answer', 'en'));
        $section = $this->section('subscription-policy', 'content');
        $section->forceDelete();
        $section->page->forceDelete();
        $this->seed(PageSectionSeeder::class);
        $this->assertSame(ContentStatus::Draft, $this->section('subscription-policy', 'content')->status);
        $this->getJson('/api/public/pages/subscription-policy?lang=en')->assertOk()->assertJsonPath('data.content', null);
    }
}
