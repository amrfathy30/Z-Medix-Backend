<?php

namespace Tests\Feature\Cms;

use App\Enums\ContentStatus;
use App\Enums\PlanFeature;
use App\Enums\SettingValueType;
use App\Filament\Pages\AccessManagementPage;
use App\Filament\Pages\SettingsPage;
use App\Filament\Pages\WebsiteContentPageSettingsPage;
use App\Filament\Resources\BlogCategoryResource\Pages\EditBlogCategory;
use App\Filament\Resources\BlogResource\Pages\EditBlog;
use App\Filament\Resources\FaqCategoryResource\Pages\EditFaqCategory;
use App\Filament\Resources\FaqResource\Pages\EditFaq;
use App\Filament\Resources\NotificationTemplateResource\Pages\EditNotificationTemplate;
use App\Filament\Resources\PageSectionItemResource\Pages\EditPageSectionItem;
use App\Filament\Resources\PageSectionResource\Pages\EditPageSection;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Models\Admin;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\NotificationTemplate;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\PageSectionItem;
use App\Models\Plan;
use App\Models\Setting;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Content\Forms\EnglishContentData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EnglishOnlyContentFormsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('filament_public');
        $permissions = [];
        foreach (['content', 'settings', 'plans', 'roles', 'admins'] as $family) {
            foreach (['view', 'create', 'update', 'delete', 'publish'] as $action) {
                $permissions[] = Permission::findOrCreate($family.'.'.$action, 'admin');
            }
        }
        $role = Role::create(['name' => 'english_editor', 'guard_name' => 'admin']);
        $role->givePermissionTo($permissions);
        $this->admin = Admin::factory()->create();
        $this->admin->assignRole($role);
        $this->actingAs($this->admin, 'admin');
    }

    public static function translatedResources(): array
    {
        return [
            'blog category' => [BlogCategory::class, EditBlogCategory::class],
            'blog' => [Blog::class, EditBlog::class],
            'faq category' => [FaqCategory::class, EditFaqCategory::class],
            'faq' => [Faq::class, EditFaq::class],
            'section item' => [PageSectionItem::class, EditPageSectionItem::class],
            'plan' => [Plan::class, EditPlan::class],
            'notification template' => [NotificationTemplate::class, EditNotificationTemplate::class],
        ];
    }

    #[DataProvider('translatedResources')]
    public function test_resource_saves_only_english_and_keeps_every_arabic_translation(string $model, string $page): void
    {
        $record = match ($model) {
            NotificationTemplate::class => NotificationTemplate::create(['key' => 'english_test', 'title' => ['en' => 'Before'], 'body' => ['en' => 'Before'], 'channels' => ['in_app']]),
            Plan::class => Plan::factory()->withFeatures(PlanFeature::Library)->create(),
            default => $model::factory()->create(),
        };
        $attributes = $record->getTranslatableAttributes();
        foreach ($attributes as $attribute) {
            $record->setTranslations($attribute, ['en' => 'Before', 'ar' => 'النص الأصلي']);
        }
        $record->save();
        $editor = Livewire::test($page, ['record' => $record->id])->assertOk();
        $updates = [];
        foreach ($attributes as $attribute) {
            $editor->assertFormFieldExists($attribute.'.en')
                ->assertFormFieldDoesNotExist($attribute.'.ar')
                ->assertFormSet([$attribute.'.en' => 'Before']);
            $updates[$attribute.'.en'] = 'Updated English';
        }
        $editor->fillForm($updates)->call('save')->assertHasNoFormErrors();
        $record->refresh();
        foreach ($attributes as $attribute) {
            $this->assertSame('Updated English', $record->getTranslation($attribute, 'en', false));
            $this->assertSame('النص الأصلي', $record->getTranslation($attribute, 'ar', false));
        }
    }

    public function test_every_defined_website_section_has_english_fields_without_language_suffixes(): void
    {
        foreach (app(ContentDefinitionRegistry::class)->allPages() as $definition) {
            $page = Page::firstOrCreate(['key' => $definition->key], ['label' => $definition->labelEn, 'status' => ContentStatus::Published]);
            foreach ($definition->orderedSections() as $sectionDefinition) {
                $section = PageSection::updateOrCreate(['page_id' => $page->id, 'section_key' => $sectionDefinition->sectionKey], [
                    'label' => $sectionDefinition->labelEn, 'data' => $sectionDefinition->defaultData, 'status' => ContentStatus::Published,
                ]);
                $editor = Livewire::test(EditPageSection::class, ['record' => $section->id])->assertOk();
                foreach ($editor->instance()->form->getFlatFields(withHidden: true) as $field) {
                    $this->assertDoesNotMatchRegularExpression('/(?:^|\\.)ar(?:\\.|$)/', $field->getName());
                    $this->assertDoesNotMatchRegularExpression('/\\((?:English|EN)\\)/', (string) $field->getLabel());
                }
            }
        }
    }

    public function test_nested_ctas_and_reordered_rows_preserve_arabic_with_the_original_item(): void
    {
        $definition = app(ContentDefinitionRegistry::class)->forSection('home', 'features');
        $page = Page::factory()->create(['key' => 'home']);
        $section = PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'features', 'data' => $definition->defaultData])->fresh();
        $before = $section->data;
        $editor = Livewire::test(EditPageSection::class, ['record' => $section->id]);
        $rows = array_values($editor->get('data.data.features'));
        $rows = array_reverse($rows);
        $rows[0]['title']['en'] = 'Reordered English';
        $rows[0]['title']['ar'] = 'Tampered';
        $editor->fillForm(['data.features' => $rows, 'data.title.en' => 'New heading'])
            ->call('save')->assertHasNoFormErrors();
        $saved = $section->refresh()->data;
        $this->assertSame('New heading', $saved['title']['en']);
        $this->assertSame($before['title']['ar'], $saved['title']['ar']);
        $this->assertSame('Reordered English', $saved['features'][0]['title']['en']);
        $this->assertSame(array_reverse($before['features'])[0]['title']['ar'], $saved['features'][0]['title']['ar']);
        $this->assertArrayNotHasKey(EnglishContentData::ROW_INDEX, $saved['features'][0]);
        $editor->call('save')->assertHasNoFormErrors();
        $this->assertSame($saved, $section->refresh()->data);

        $ctaDefinition = app(ContentDefinitionRegistry::class)->forSection('services', 'cta');
        $servicePage = Page::factory()->create(['key' => 'services']);
        $cta = PageSection::factory()->create(['page_id' => $servicePage->id, 'section_key' => 'cta', 'data' => [...$ctaDefinition->defaultData, 'cta' => ['text' => ['en' => 'Start', 'ar' => 'ابدأ'], 'url' => 'https://example.test']]]);
        $arabic = $cta->data['cta']['text']['ar'];
        Livewire::test(EditPageSection::class, ['record' => $cta->id])
            ->assertFormFieldDoesNotExist('data.cta.text.ar')
            ->fillForm(['data.cta.text.en' => 'Start here'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Start here', $cta->refresh()->data['cta']['text']['en']);
        $this->assertSame($arabic, $cta->data['cta']['text']['ar']);
    }

    public function test_explicit_row_deletion_is_allowed_and_stale_editors_cannot_overwrite_content(): void
    {
        $definition = app(ContentDefinitionRegistry::class)->forSection('home', 'features');
        $page = Page::factory()->create(['key' => 'home']);
        $section = PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'features', 'data' => $definition->defaultData])->fresh();
        $before = $section->data;
        Livewire::test(EditPageSection::class, ['record' => $section->id])
            ->fillForm(['data.features' => []])->call('save')->assertHasNoFormErrors();
        $before['features'] = [];
        $this->assertSame($before, $section->refresh()->data);
        $editor = Livewire::test(EditPageSection::class, ['record' => $section->id]);
        $changed = $before;
        $changed['title']['ar'] = 'تحديث آخر';
        $section->update(['data' => $changed]);
        $editor->fillForm(['data.title.en' => 'Stale edit'])->call('save')->assertHasErrors();
        $this->assertSame($changed, $section->refresh()->data);
    }

    public function test_settings_and_seo_save_english_without_clearing_arabic_or_null_values(): void
    {
        $setting = Setting::factory()->create(['key' => 'footer_description_1', 'group' => 'footer', 'is_public' => true,
            'value_type' => SettingValueType::Json, 'value' => '{"en":"Before","ar":null}']);
        Livewire::test(SettingsPage::class)
            ->assertFormFieldDoesNotExist("setting_{$setting->id}.ar")
            ->assertFormSet(["setting_{$setting->id}.en" => 'Before'])
            ->fillForm(["setting_{$setting->id}.en" => 'After'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['en' => 'After', 'ar' => null], json_decode($setting->fresh()->value, true));
        $page = Page::factory()->create(['key' => 'privacy-policy', 'meta_title' => ['en' => 'Before', 'ar' => 'عنوان'],
            'meta_description' => ['en' => 'Before', 'ar' => 'وصف'], 'public_path' => ['en' => '/privacy', 'ar' => '/ar/privacy']]);
        Livewire::test(WebsiteContentPageSettingsPage::class, ['pageKey' => 'privacy-policy'])
            ->assertFormFieldDoesNotExist('meta_title.ar')->assertFormFieldDoesNotExist('public_path.ar')
            ->fillForm(['meta_title.en' => '', 'meta_description.en' => 'After', 'public_path.en' => '/new-privacy'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('عنوان', $page->fresh()->getTranslation('meta_title', 'ar', false));
        $this->assertSame('وصف', $page->fresh()->getTranslation('meta_description', 'ar', false));
        $this->assertSame('/ar/privacy', $page->fresh()->getTranslation('public_path', 'ar', false));
    }

    public function test_role_name_form_is_english_only_and_preserves_arabic_and_permissions(): void
    {
        $role = Role::create(['name' => 'legacy', 'guard_name' => 'admin', 'display_name_en' => 'Legacy', 'display_name_ar' => 'قديم']);
        $role->givePermissionTo('content.view');
        Livewire::test(AccessManagementPage::class)
            ->mountAction('editRole', ['roleId' => $role->id])
            ->assertActionDataSet(['display_name_en' => 'Legacy'])
            ->assertFormFieldDoesNotExist('display_name_ar')
            ->setActionData(['display_name_en' => 'Updated', 'permissions' => ['content.view']])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('قديم', $role->fresh()->display_name_ar);
        $this->assertSame('Updated', $role->fresh()->display_name_en);
        $this->assertTrue($role->fresh()->hasPermissionTo('content.view'));
    }

    public static function legalPages(): array
    {
        return [['privacy-policy'], ['terms-and-conditions'], ['subscription-policy']];
    }

    #[DataProvider('legalPages')]
    public function test_legal_content_saves_english_and_serves_untouched_arabic(string $key): void
    {
        $page = Page::firstOrCreate(['key' => $key], ['label' => $key, 'status' => ContentStatus::Published]);
        $section = PageSection::updateOrCreate(['page_id' => $page->id, 'section_key' => 'content'], [
            'data' => ['title' => ['en' => 'Policy', 'ar' => 'السياسة'], 'body' => ['en' => '<p>Before</p>', 'ar' => '<p>النص السابق</p>']],
            'status' => $key === 'subscription-policy' ? ContentStatus::Draft : ContentStatus::Published,
        ]);
        $editor = Livewire::test(EditPageSection::class, ['record' => $section->id])
            ->assertFormSet(['data.body.en' => '<p>Before</p>'])
            ->assertFormFieldDoesNotExist('data.body.ar')
            ->fillForm(['data.body.en' => '<p>New terms</p>'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(ContentStatus::Published, $section->refresh()->status);
        $this->assertSame('<p>النص السابق</p>', $section->data['body']['ar']);
        $this->getJson('/api/public/pages/'.$key.'?lang=en')->assertOk()->assertJsonPath('data.content', '<p>New terms</p>');
        $this->getJson('/api/public/pages/'.$key.'?lang=ar')->assertOk()->assertJsonPath('data.content', '<p>النص السابق</p>');
    }

    public function test_english_only_rows_can_be_added_and_deleted_without_changing_arabic_rows(): void
    {
        $definition = app(ContentDefinitionRegistry::class)->forSection('home', 'features');
        $content = $definition->defaultData;
        $content['features'] = array_slice($content['features'], 0, 2);
        $page = Page::factory()->create(['key' => 'home']);
        $section = PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'features', 'data' => $content])->fresh();
        $before = $section->data['features'];
        $editor = Livewire::test(EditPageSection::class, ['record' => $section->id]);
        $rows = array_values($editor->get('data.data.features'));
        $rows[] = ['title' => ['en' => 'New row'], 'description' => ['en' => 'New text']];
        $editor->fillForm(['data.features' => $rows])->call('save')->assertHasNoFormErrors();
        $saved = $section->refresh()->data['features'];
        $this->assertCount(count($before) + 1, $saved);
        $this->assertSame(['en' => 'New row'], end($saved)['title']);
        $rows = array_values($editor->get('data.data.features'));
        array_pop($rows);
        $editor->fillForm(['data.features' => $rows])->call('save')->assertHasNoFormErrors();
        $this->assertSame($before, $section->refresh()->data['features']);
    }

    public function test_content_and_publishing_permissions_are_still_enforced(): void
    {
        $role = $this->admin->roles->first();
        $role->revokePermissionTo('content.publish');
        $blog = Blog::factory()->draft()->create();
        Livewire::test(EditBlog::class, ['record' => $blog->id])
            ->fillForm(['status' => 'published'])->call('save')->assertHasFormErrors(['status']);
        $this->assertSame(ContentStatus::Draft, $blog->refresh()->status);
        $role->revokePermissionTo('content.update');
        $this->admin->unsetRelation('roles')->unsetRelation('permissions');
        Livewire::test(EditBlog::class, ['record' => $blog->id])->assertForbidden();
        $section = PageSection::query()->where('section_key', 'content')->firstOrFail();
        Livewire::test(EditPageSection::class, ['record' => $section->id])->assertForbidden();
    }

    public function test_nested_repeater_reordering_preserves_both_levels_of_arabic_content(): void
    {
        $definition = app(ContentDefinitionRegistry::class)->forSection('home', 'plans');
        $page = Page::factory()->create(['key' => 'home']);
        $section = PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'plans', 'data' => $definition->defaultData])->fresh();
        $before = $section->data['plans'];
        $editor = Livewire::test(EditPageSection::class, ['record' => $section->id]);
        $plans = array_reverse(array_values($editor->get('data.data.plans')));
        $plans[0]['features'] = array_reverse(array_values($plans[0]['features']));
        $plans[0]['features'][0]['label']['en'] = 'New English feature';
        $editor->fillForm(['data.plans' => $plans])->call('save')->assertHasNoFormErrors();
        $saved = $section->refresh()->data['plans'];
        $original = array_reverse($before)[0];
        $this->assertSame($original['title']['ar'], $saved[0]['title']['ar']);
        $this->assertSame(array_reverse($original['features'])[0]['label']['ar'], $saved[0]['features'][0]['label']['ar']);
        $this->assertSame('New English feature', $saved[0]['features'][0]['label']['en']);
        $this->assertArrayNotHasKey(EnglishContentData::ROW_INDEX, $saved[0]['features'][0]);
        $editor->call('save')->assertHasNoFormErrors();
        $this->assertSame($saved, $section->refresh()->data['plans']);
    }

    public function test_invalid_or_duplicated_repeater_references_do_not_change_stored_content(): void
    {
        $definition = app(ContentDefinitionRegistry::class)->forSection('home', 'features');
        $page = Page::factory()->create(['key' => 'home']);
        $section = PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'features', 'data' => $definition->defaultData])->fresh();
        $before = $section->data;
        foreach ([999, 0] as $invalidIndex) {
            $editor = Livewire::test(EditPageSection::class, ['record' => $section->id]);
            $rows = array_values($editor->get('data.data.features'));
            $rows[1][EnglishContentData::ROW_INDEX] = $invalidIndex;
            $editor->fillForm(['data.features' => $rows])->call('save')->assertHasFormErrors(['data.features']);
            $this->assertSame($before, $section->refresh()->data);
        }
    }
}
