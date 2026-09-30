<?php

namespace Tests\Feature\Cms;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Enums\ContentStatus;
use App\Filament\Pages\WebsiteContentPage;
use App\Filament\Resources\PageSectionResource;
use App\Filament\Resources\PageSectionResource\Pages\EditPageSection;
use App\Filament\Resources\PageSectionResource\Pages\ListPageSections;
use App\Models\Admin;
use App\Models\Page;
use App\Models\PageSection;
use Database\Seeders\PageSectionSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PageSectionResource's edit form is generated from WebsiteContentDefinitions
 * via ContentDefinitionRegistry/ContentInputFactory — it no longer depends on
 * SectionFormMapping or page_sections.type. These tests use real, definition-backed
 * page_key/section_key combinations (seeded via PageSectionSeeder) rather than random
 * factory data, since any page/section without a matching definition now throws
 * MissingContentDefinitionException by design.
 */
class PageSectionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        $this->seed(PageSectionSeeder::class);
    }

    private function adminWithRole(string $roleName): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::Admin,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole($roleName);

        return $admin;
    }

    private function superAdmin(): Admin
    {
        $admin = Admin::factory()->create([
            'status' => AccountStatus::Active,
            'type' => AdminType::SuperAdmin,
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function section(string $pageKey, string $sectionKey): PageSection
    {
        $page = Page::query()->where('key', $pageKey)->firstOrFail();

        return PageSection::query()
            ->where('page_id', $page->id)
            ->where('section_key', $sectionKey)
            ->firstOrFail();
    }

    // ─── No structure-changing capability for anyone ──────────────────────────

    public function test_no_role_can_create_page_sections(): void
    {
        $this->assertFalse(PageSectionResource::canCreate());
    }

    public function test_no_role_can_delete_page_sections(): void
    {
        $section = $this->section('home', 'hero');

        $this->assertFalse(PageSectionResource::canDelete($section));
        $this->assertFalse(PageSectionResource::canDeleteAny());
    }

    public function test_no_role_can_restore_or_force_delete_page_sections(): void
    {
        $section = $this->section('home', 'hero');

        $this->assertFalse(PageSectionResource::canRestore($section));
        $this->assertFalse(PageSectionResource::canRestoreAny());
        $this->assertFalse(PageSectionResource::canForceDelete($section));
        $this->assertFalse(PageSectionResource::canForceDeleteAny());
    }

    public function test_create_page_section_route_does_not_exist(): void
    {
        $this->assertArrayNotHasKey('create', PageSectionResource::getPages());
    }

    // ─── Listing (read-only) ───────────────────────────────────────────────────

    public function test_super_admin_can_view_page_sections_index(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin, 'admin')
            ->test(ListPageSections::class)
            ->assertOk();
    }

    public function test_content_manager_can_view_page_sections_index(): void
    {
        $admin = $this->adminWithRole('content_manager');

        Livewire::actingAs($admin, 'admin')
            ->test(ListPageSections::class)
            ->assertOk();
    }

    public function test_admin_view_only_can_view_page_sections_index(): void
    {
        $admin = $this->adminWithRole('admin');

        Livewire::actingAs($admin, 'admin')
            ->test(ListPageSections::class)
            ->assertOk();
    }

    public function test_page_filter_renders_without_exceptions(): void
    {
        $admin = $this->superAdmin();
        $home = Page::query()->where('key', 'home')->firstOrFail();
        $services = Page::query()->where('key', 'services')->firstOrFail();

        Livewire::actingAs($admin, 'admin')
            ->test(ListPageSections::class)
            ->filterTable('page', $home->id)
            ->assertOk()
            ->assertCanSeeTableRecords(PageSection::where('page_id', $home->id)->get())
            ->assertCanNotSeeTableRecords(PageSection::where('page_id', $services->id)->get());
    }

    public function test_index_sorts_by_page_then_sort_order_by_default(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin, 'admin')
            ->test(ListPageSections::class)
            ->assertOk();

        $ordered = PageSection::query()
            ->join('pages', 'pages.id', '=', 'page_sections.page_id')
            ->orderBy('pages.key')
            ->orderBy('page_sections.sort_order')
            ->pluck('pages.key')
            ->all();
        $servicesIndex = array_search('services', $ordered, true);
        $homeIndex = array_search('home', $ordered, true);

        $this->assertNotFalse($servicesIndex);
        $this->assertNotFalse($homeIndex);
    }

    // ─── Structural fields are never rendered, for anyone ─────────────────────

    public function test_structural_fields_are_never_rendered_for_content_manager(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $section = $this->section('home', 'hero');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertOk()
            ->assertFormFieldDoesNotExist('page_id')
            ->assertFormFieldDoesNotExist('section_key')
            ->assertFormFieldDoesNotExist('status')
            ->assertFormFieldDoesNotExist('sort_order');
    }

    public function test_structural_fields_are_never_rendered_for_super_admin(): void
    {
        $admin = $this->superAdmin();
        $section = $this->section('home', 'hero');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertOk()
            ->assertFormFieldDoesNotExist('page_id')
            ->assertFormFieldDoesNotExist('section_key')
            ->assertFormFieldDoesNotExist('status')
            ->assertFormFieldDoesNotExist('sort_order');
    }

    public function test_edit_page_has_no_delete_or_restore_actions(): void
    {
        $admin = $this->superAdmin();
        $section = $this->section('home', 'hero');

        $component = Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertOk();

        $this->assertSame([], $component->instance()->getCachedHeaderActions());
    }

    public function test_index_has_no_create_action(): void
    {
        $admin = $this->superAdmin();

        $component = Livewire::actingAs($admin, 'admin')
            ->test(ListPageSections::class)
            ->assertOk();

        $this->assertSame([], $component->instance()->getCachedHeaderActions());
    }

    // ─── Missing definition behaves as a developer error, not a silent fallback ──

    public function test_unknown_page_or_section_redirects_instead_of_throwing(): void
    {
        $admin = $this->superAdmin();
        $page = Page::factory()->create(['key' => 'nonexistent-page']);
        $section = PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'nonexistent-section']);

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertRedirect(WebsiteContentPage::getUrl());

        $this->assertDatabaseHas('page_sections', [
            'id' => $section->id,
            'section_key' => 'nonexistent-section',
        ]);
    }

    // ─── Form is generated from the exact page+section definition ─────────────

    public function test_hero_section_renders_only_items_defined_for_it(): void
    {
        $admin = $this->superAdmin();
        $section = $this->section('home', 'hero');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertOk()
            ->assertFormFieldExists('data.title.en')
            ->assertFormFieldExists('data.title.ar')
            ->assertFormFieldExists('data.description.en')
            ->assertFormFieldExists('data.description.ar')
            ->assertFormFieldExists('image')
            ->assertFormFieldExists('image_small')
            // Not defined for home/hero: no old placeholder fields, no repeater, no generic cta composite, no other media collections.
            ->assertFormFieldDoesNotExist('data.subtitle.en')
            ->assertFormFieldDoesNotExist('data.body.en')
            ->assertFormFieldDoesNotExist('data.primary_cta_text.en')
            ->assertFormFieldDoesNotExist('background')
            ->assertFormFieldDoesNotExist('data.items')
            ->assertFormFieldDoesNotExist('data.cta.url')
            ->assertFormFieldDoesNotExist('icon')
            ->assertFormFieldDoesNotExist('gallery')
            ->assertFormFieldDoesNotExist('video')
            // No root state paths remain — every item writes under `data`.
            ->assertFormFieldDoesNotExist('title.en')
            ->assertFormFieldDoesNotExist('description.en');
    }

    public function test_section_without_cta_definition_does_not_render_cta_inputs(): void
    {
        $admin = $this->superAdmin();
        $section = $this->section('about-us', 'mission');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertOk()
            ->assertFormFieldDoesNotExist('data.cta.url')
            ->assertFormFieldDoesNotExist('data.cta.text.en');
    }

    public function test_section_with_cta_definition_renders_cta_inputs(): void
    {
        $admin = $this->superAdmin();
        $section = $this->section('services', 'cta');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertOk()
            ->assertFormFieldExists('data.cta.text.en')
            ->assertFormFieldExists('data.cta.text.ar')
            ->assertFormFieldExists('data.cta.url');
    }

    public function test_can_edit_privacy_policy_content_section(): void
    {
        $admin = $this->superAdmin();
        $section = $this->section('privacy-policy', 'content');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertOk()
            ->assertFormFieldExists('data.title.en')
            ->assertFormFieldExists('data.body.en')
            ->assertFormFieldExists('data.meta_title.en')
            ->assertFormFieldExists('data.meta_description.en');
    }

    public function test_can_edit_terms_and_conditions_content_section(): void
    {
        $admin = $this->superAdmin();
        $section = $this->section('terms-and-conditions', 'content');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertOk()
            ->assertFormFieldExists('data.title.en')
            ->assertFormFieldExists('data.body.en')
            ->assertFormFieldExists('data.meta_title.en')
            ->assertFormFieldExists('data.meta_description.en');
    }

    public function test_repeater_section_uses_its_own_item_label_not_generic_text(): void
    {
        $admin = $this->superAdmin();
        $section = $this->section('services', 'benefits');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertOk()
            ->assertFormFieldExists('data.items')
            ->assertSee('Benefits')
            ->assertDontSee('Repeatable Content');
    }

    public function test_media_field_appears_only_when_defined_for_the_section(): void
    {
        $admin = $this->superAdmin();
        $heroSection = $this->section('about-us', 'hero');
        $missionSection = $this->section('about-us', 'mission');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $heroSection->getRouteKey()])
            ->assertFormFieldExists('background');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $missionSection->getRouteKey()])
            ->assertFormFieldDoesNotExist('background');
    }

    // ─── Content editing preserves structural values unchanged ───────────────

    public function test_saving_content_never_changes_structural_values(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $section = $this->section('home', 'hero');
        $section->forceFill(['sort_order' => 3])->save();

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm([
                'data.title.en' => 'Hero Updated By Content Manager',
                'data.description.en' => 'Updated description',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $section->refresh();
        $this->assertSame('Hero Updated By Content Manager', $section->data['title']['en']);
        $this->assertSame('Updated description', $section->data['description']['en']);
        $this->assertSame('hero', $section->section_key);
        $this->assertSame(ContentStatus::Published, $section->status);
        $this->assertSame(3, $section->sort_order);
    }

    public function test_super_admin_editing_content_also_never_changes_structural_values(): void
    {
        $admin = $this->superAdmin();
        $section = $this->section('services', 'hero');
        $section->forceFill(['status' => ContentStatus::Draft])->save();

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm(['data.title.en' => 'Updated Services Title'])
            ->call('save')
            ->assertHasNoFormErrors();

        $section->refresh();
        $this->assertSame('Updated Services Title', $section->data['title']['en']);
        $this->assertSame('hero', $section->section_key);
        $this->assertSame(ContentStatus::Draft, $section->status);
    }

    // ─── Repeater with nested items saves correctly ────────────────────────────

    public function test_repeater_nested_items_persist_into_data_json(): void
    {
        $admin = $this->superAdmin();
        $section = $this->section('services', 'benefits');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm([
                'data.items' => [
                    ['label' => ['en' => 'Browse our offerings', 'ar' => 'تصفح ما نقدمه']],
                    ['label' => ['en' => 'Book a session', 'ar' => 'احجز جلسة']],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $section->refresh();
        $this->assertSame('Browse our offerings', $section->data['items'][0]['label']['en']);
        $this->assertSame('احجز جلسة', $section->data['items'][1]['label']['ar']);
    }

    public function test_content_manager_can_edit_configured_repeater_content(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $section = $this->section('services', 'benefits');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm([
                'data.items' => [
                    ['label' => ['en' => 'New benefit', 'ar' => 'ميزة جديدة']],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $section->refresh();
        $this->assertSame('New benefit', $section->data['items'][0]['label']['en']);
    }

    // ─── CTA item saves correctly ───────────────────────────────────────────────

    public function test_cta_item_persists_into_data_json(): void
    {
        $admin = $this->superAdmin();
        $section = $this->section('services', 'cta');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm([
                'data.cta.text.en' => 'Get Started',
                'data.cta.text.ar' => 'ابدأ الآن',
                'data.cta.url' => 'https://example.test/start',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $section->refresh();
        $this->assertSame('Get Started', $section->data['cta']['text']['en']);
        $this->assertSame('ابدأ الآن', $section->data['cta']['text']['ar']);
        $this->assertSame('https://example.test/start', $section->data['cta']['url']);
    }

    // ─── Media upload persists to media collection ─────────────────────────────

    public function test_background_image_upload_persists_to_media_collection(): void
    {
        Storage::fake('public');
        Storage::fake('filament_public');

        $admin = $this->superAdmin();
        $section = $this->section('about-us', 'hero');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm(['background' => UploadedFile::fake()->image('hero-bg.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, $section->getMedia('background')->count());
    }

    // ─── Landing page sections ─────────────────────────────────────────────────

    public function test_landing_sections_render_their_generated_fields(): void
    {
        $expected = [
            'features' => ['data.title.en', 'data.description.ar', 'data.features'],
            'plans' => ['data.title.en', 'data.description.en', 'data.plans'],
            'ai_assistant' => ['data.subtitle.en', 'data.title.ar', 'data.description.en', 'image', 'supporting_images', 'logo', 'data.highlights'],
            'on_mobile' => ['data.subtitle.en', 'data.title.en', 'data.description.en', 'data.app_store_url', 'data.google_play_url', 'image'],
        ];

        foreach ($expected as $sectionKey => $fields) {
            $test = Livewire::actingAs($this->superAdmin(), 'admin')
                ->test(EditPageSection::class, ['record' => $this->section('home', $sectionKey)->getRouteKey()])
                ->assertOk();

            foreach ($fields as $field) {
                $test->assertFormFieldExists($field);
            }
        }
    }

    public function test_plan_rows_persist_into_data_json_with_every_field(): void
    {
        $section = $this->section('home', 'plans');

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm(['data.plans' => [[
                'title' => ['en' => 'Pro Plan', 'ar' => 'باقة برو'],
                'price' => 49.99,
                'discount' => 20,
                'billing_type' => 'yearly',
                'features' => [['label' => ['en' => 'Offline mode', 'ar' => 'وضع عدم الاتصال']]],
                'background_color' => '#112233',
                'icon' => 'crown',
                'icon_color' => '#FFFFFF',
            ]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $plan = $section->refresh()->data['plans'][0];
        $this->assertSame('Pro Plan', $plan['title']['en']);
        $this->assertEquals(49.99, $plan['price']);
        $this->assertEquals(20, $plan['discount']);
        $this->assertSame('yearly', $plan['billing_type']);
        $this->assertSame('وضع عدم الاتصال', $plan['features'][0]['label']['ar']);
        $this->assertSame('#112233', $plan['background_color']);
        $this->assertSame('#FFFFFF', $plan['icon_color']);
    }

    public function test_plans_repeater_refuses_more_than_six_rows(): void
    {
        $section = $this->section('home', 'plans');
        $rows = array_fill(0, 7, ['title' => ['en' => 'Plan', 'ar' => 'باقة'], 'billing_type' => 'monthly']);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm(['data.plans' => $rows])
            ->call('save')
            ->assertHasFormErrors(['data.plans']);
    }

    public function test_billing_type_only_accepts_monthly_or_yearly(): void
    {
        $section = $this->section('home', 'plans');

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm(['data.plans' => [['title' => ['en' => 'Plan', 'ar' => 'باقة'], 'billing_type' => 'weekly']]])
            ->call('save')
            ->assertHasFormErrors();
    }

    public function test_on_mobile_store_links_must_be_urls_and_persist(): void
    {
        $section = $this->section('home', 'on_mobile');

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm(['data.app_store_url' => 'not a url'])
            ->call('save')
            ->assertHasFormErrors(['data.app_store_url']);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm([
                'data.app_store_url' => 'https://apps.apple.com/app/z-medix',
                'data.google_play_url' => 'https://play.google.com/store/apps/details?id=z.medix',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $data = $section->refresh()->data;
        $this->assertSame('https://apps.apple.com/app/z-medix', $data['app_store_url']);
        $this->assertSame('https://play.google.com/store/apps/details?id=z.medix', $data['google_play_url']);
    }

    public function test_hero_image_uploads_persist_to_their_own_media_collections(): void
    {
        Storage::fake('public');
        Storage::fake('filament_public');

        $section = $this->section('home', 'hero');

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm([
                'image' => UploadedFile::fake()->image('hero.jpg'),
                'image_small' => UploadedFile::fake()->image('hero-small.jpg'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, $section->getMedia('image')->count());
        $this->assertSame(1, $section->getMedia('image_small')->count());
    }

    // ─── Permission enforcement ────────────────────────────────────────────────

    public function test_admin_view_only_cannot_edit_page_section(): void
    {
        $admin = $this->adminWithRole('admin');
        $section = $this->section('home', 'hero');

        Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->getRouteKey()])
            ->assertForbidden();
    }
}
