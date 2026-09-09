<?php

namespace Tests\Feature\Cms;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Enums\ContactMessageStatus;
use App\Enums\ContentStatus;
use App\Filament\Pages\SettingsPage;
use App\Filament\Resources\BlogCategoryResource\Pages\CreateBlogCategory;
use App\Filament\Resources\BlogCategoryResource\Pages\EditBlogCategory;
use App\Filament\Resources\BlogCategoryResource\Pages\ListBlogCategories;
use App\Filament\Resources\BlogResource\Pages\CreateBlog;
use App\Filament\Resources\BlogResource\Pages\EditBlog;
use App\Filament\Resources\BlogResource\Pages\ListBlogs;
use App\Filament\Resources\ContactMessageResource\Pages\EditContactMessage;
use App\Filament\Resources\ContactMessageResource\Pages\ListContactMessages;
use App\Filament\Resources\ContactMessageResource\Pages\ViewContactMessage;
use App\Filament\Resources\FaqCategoryResource\Pages\CreateFaqCategory;
use App\Filament\Resources\FaqCategoryResource\Pages\EditFaqCategory;
use App\Filament\Resources\FaqCategoryResource\Pages\ListFaqCategories;
use App\Filament\Resources\FaqResource\Pages\CreateFaq;
use App\Filament\Resources\FaqResource\Pages\EditFaq;
use App\Filament\Resources\FaqResource\Pages\ListFaqs;
use App\Filament\Resources\PageResource;
use App\Models\Admin;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\ContactMessage;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\Page;
use App\Models\Setting;
use Database\Seeders\CmsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentCmsResourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

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

    // ─── BlogCategoryResource ─────────────────────────────────────────────────

    public function test_content_manager_can_list_blog_categories(): void
    {
        $admin = $this->adminWithRole('content_manager');

        Livewire::actingAs($admin, 'admin')
            ->test(ListBlogCategories::class)
            ->assertOk();
    }

    public function test_content_manager_can_create_blog_category(): void
    {
        $admin = $this->adminWithRole('content_manager');

        Livewire::actingAs($admin, 'admin')
            ->test(CreateBlogCategory::class)
            ->fillForm([
                'name.en' => 'Technology',
                'name.ar' => 'تقنية',
                'slug' => 'technology',
                'status' => ContentStatus::Published->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('blog_categories', ['slug' => 'technology']);
    }

    public function test_content_manager_can_edit_blog_category(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $category = BlogCategory::factory()->create();

        Livewire::actingAs($admin, 'admin')
            ->test(EditBlogCategory::class, ['record' => $category->getRouteKey()])
            ->assertOk()
            ->fillForm([
                'name.en' => 'Updated Category',
                'slug' => $category->slug,
                'status' => ContentStatus::Published->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    // ─── BlogResource ─────────────────────────────────────────────────────────

    public function test_content_manager_can_list_blogs(): void
    {
        $admin = $this->adminWithRole('content_manager');

        Livewire::actingAs($admin, 'admin')
            ->test(ListBlogs::class)
            ->assertOk();
    }

    public function test_content_manager_can_create_blog(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $category = BlogCategory::factory()->create();

        Livewire::actingAs($admin, 'admin')
            ->test(CreateBlog::class)
            ->fillForm([
                'title.en' => 'My First Blog',
                'title.ar' => 'مدونتي الأولى',
                'slug' => 'my-first-blog',
                'blog_category_id' => $category->id,
                'status' => ContentStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('blogs', ['slug' => 'my-first-blog']);
    }

    public function test_blog_category_relation_works_on_edit(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $category = BlogCategory::factory()->create();
        $blog = Blog::factory()->create(['blog_category_id' => $category->id]);

        Livewire::actingAs($admin, 'admin')
            ->test(EditBlog::class, ['record' => $blog->getRouteKey()])
            ->assertOk()
            ->assertFormSet(['blog_category_id' => (string) $category->id]);
    }

    public function test_content_manager_can_publish_blog_from_edit_page(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $blog = Blog::factory()->draft()->create();

        Livewire::actingAs($admin, 'admin')
            ->test(EditBlog::class, ['record' => $blog->getRouteKey()])
            ->callAction('publish');

        $this->assertDatabaseHas('blogs', ['id' => $blog->id, 'status' => ContentStatus::Published->value]);
    }

    // ─── FaqCategoryResource ──────────────────────────────────────────────────

    public function test_content_manager_can_list_faq_categories(): void
    {
        $admin = $this->adminWithRole('content_manager');

        Livewire::actingAs($admin, 'admin')
            ->test(ListFaqCategories::class)
            ->assertOk();
    }

    public function test_content_manager_can_create_faq_category(): void
    {
        $admin = $this->adminWithRole('content_manager');

        Livewire::actingAs($admin, 'admin')
            ->test(CreateFaqCategory::class)
            ->fillForm([
                'name.en' => 'Billing',
                'name.ar' => 'الفواتير',
                'slug' => 'billing',
                'status' => ContentStatus::Published->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('faq_categories', ['slug' => 'billing']);
    }

    public function test_content_manager_can_edit_faq_category(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $category = FaqCategory::factory()->create();

        Livewire::actingAs($admin, 'admin')
            ->test(EditFaqCategory::class, ['record' => $category->getRouteKey()])
            ->assertOk();
    }

    // ─── FaqResource ──────────────────────────────────────────────────────────

    public function test_content_manager_can_list_faqs(): void
    {
        $admin = $this->adminWithRole('content_manager');

        Livewire::actingAs($admin, 'admin')
            ->test(ListFaqs::class)
            ->assertOk();
    }

    public function test_content_manager_can_create_faq(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $category = FaqCategory::factory()->create();

        Livewire::actingAs($admin, 'admin')
            ->test(CreateFaq::class)
            ->fillForm([
                'question.en' => 'How do I reset my password?',
                'question.ar' => 'كيف أعيد تعيين كلمة المرور؟',
                'answer.en' => 'Click forgot password.',
                'faq_category_id' => $category->id,
                'status' => ContentStatus::Published->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_faq_category_relation_works_on_edit(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $category = FaqCategory::factory()->create();
        $faq = Faq::factory()->create(['faq_category_id' => $category->id]);

        Livewire::actingAs($admin, 'admin')
            ->test(EditFaq::class, ['record' => $faq->getRouteKey()])
            ->assertOk()
            ->assertFormSet(['faq_category_id' => (string) $category->id]);
    }

    // ─── SettingsPage ─────────────────────────────────────────────────────────

    public function test_super_admin_can_access_settings_page(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin, 'admin')
            ->test(SettingsPage::class)
            ->assertOk();
    }

    public function test_content_manager_can_access_settings_page(): void
    {
        $admin = $this->adminWithRole('content_manager');

        Livewire::actingAs($admin, 'admin')
            ->test(SettingsPage::class)
            ->assertOk();
    }

    public function test_settings_page_displays_grouped_settings(): void
    {
        Setting::factory()->create(['key' => 'site_name', 'group' => 'general', 'is_public' => true, 'value' => 'Starter Platform']);
        Setting::factory()->create(['key' => 'footer_copyright', 'group' => 'footer', 'is_public' => true, 'value' => '© Starter Platform']);
        Setting::factory()->create(['key' => 'social_twitter', 'group' => 'social', 'is_public' => true, 'value' => 'https://x.com/example']);

        $admin = $this->superAdmin();

        $component = Livewire::actingAs($admin, 'admin')
            ->test(SettingsPage::class);

        $groups = $component->instance()->getGroupedSettings();

        $this->assertArrayHasKey('general', $groups);
        $this->assertArrayHasKey('footer', $groups);
        $this->assertArrayHasKey('social', $groups);
    }

    public function test_settings_page_does_not_display_home_group(): void
    {
        Setting::factory()->create(['key' => 'hero_title', 'group' => 'home', 'is_public' => true, 'value' => 'Welcome']);

        $admin = $this->superAdmin();

        $component = Livewire::actingAs($admin, 'admin')
            ->test(SettingsPage::class);

        $groups = $component->instance()->getGroupedSettings();

        $this->assertArrayNotHasKey('home', $groups);
        $component->assertDontSee(__('admin.cms.settings_group_home'));
    }

    public function test_settings_table_still_contains_home_group_records_after_seeding(): void
    {
        $this->seed(CmsSeeder::class);

        $this->assertDatabaseHas('settings', ['group' => 'home', 'key' => 'hero_title']);
        $this->assertGreaterThan(0, Setting::where('group', 'home')->count());
    }

    public function test_content_manager_can_save_settings(): void
    {
        $setting = Setting::factory()->create([
            'key' => 'site_name',
            'group' => 'general',
            'is_public' => true,
            'value' => 'Old Name',
            'value_type' => 'string',
        ]);

        $admin = $this->adminWithRole('content_manager');

        Livewire::actingAs($admin, 'admin')
            ->test(SettingsPage::class)
            ->fillForm(["setting_{$setting->id}" => 'New Name'])
            ->call('save');

        $this->assertDatabaseHas('settings', ['id' => $setting->id, 'value' => 'New Name']);
    }

    public function test_footer_settings_still_save_correctly(): void
    {
        $setting = Setting::factory()->create([
            'key' => 'footer_copyright',
            'group' => 'footer',
            'is_public' => true,
            'value' => '© Old',
            'value_type' => 'string',
        ]);

        $admin = $this->superAdmin();

        Livewire::actingAs($admin, 'admin')
            ->test(SettingsPage::class)
            ->fillForm(["setting_{$setting->id}" => '© 2026 Starter Platform'])
            ->call('save');

        $this->assertDatabaseHas('settings', ['id' => $setting->id, 'value' => '© 2026 Starter Platform']);
    }

    public function test_admin_view_only_cannot_access_settings_page(): void
    {
        $admin = $this->adminWithRole('admin');

        Livewire::actingAs($admin, 'admin')
            ->test(SettingsPage::class)
            ->assertForbidden();
    }

    public function test_settings_page_get_setting_label_returns_translation_for_known_key(): void
    {
        $this->assertSame('Site Name', SettingsPage::getSettingLabel('site_name'));
        $this->assertSame('Footer Contact Email', SettingsPage::getSettingLabel('footer_contact_email'));
        $this->assertSame('Twitter / X URL', SettingsPage::getSettingLabel('social_twitter'));
        $this->assertSame('Hero Title', SettingsPage::getSettingLabel('hero_title'));
        $this->assertSame('Copyright Notice', SettingsPage::getSettingLabel('footer_copyright'));
    }

    public function test_settings_page_get_setting_label_falls_back_to_humanized_for_unknown_key(): void
    {
        $this->assertSame('My Custom Key', SettingsPage::getSettingLabel('my_custom_key'));
        $this->assertSame('Another Unknown Setting', SettingsPage::getSettingLabel('another_unknown_setting'));
    }

    public function test_settings_page_arabic_locale_returns_arabic_labels(): void
    {
        app()->setLocale('ar');

        $this->assertSame('اسم الموقع', SettingsPage::getSettingLabel('site_name'));
        $this->assertSame('بريد التواصل في التذييل', SettingsPage::getSettingLabel('footer_contact_email'));
        $this->assertSame('نص حقوق النشر', SettingsPage::getSettingLabel('footer_copyright'));

        app()->setLocale('en');
    }

    // ─── ContactMessageResource ───────────────────────────────────────────────

    public function test_content_manager_can_list_contact_messages(): void
    {
        $admin = $this->adminWithRole('content_manager');

        Livewire::actingAs($admin, 'admin')
            ->test(ListContactMessages::class)
            ->assertOk();
    }

    public function test_content_manager_can_view_contact_message(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $message = ContactMessage::factory()->create();

        Livewire::actingAs($admin, 'admin')
            ->test(ViewContactMessage::class, ['record' => $message->getRouteKey()])
            ->assertOk();
    }

    public function test_content_manager_can_update_contact_message_status(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $message = ContactMessage::factory()->create(['status' => ContactMessageStatus::New]);

        Livewire::actingAs($admin, 'admin')
            ->test(EditContactMessage::class, ['record' => $message->getRouteKey()])
            ->fillForm(['status' => ContactMessageStatus::InReview->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('contact_messages', [
            'id' => $message->id,
            'status' => ContactMessageStatus::InReview->value,
        ]);
    }

    public function test_admin_view_only_cannot_list_contact_messages(): void
    {
        $admin = $this->adminWithRole('admin');

        Livewire::actingAs($admin, 'admin')
            ->test(ListContactMessages::class)
            ->assertForbidden();
    }

    // ─── Permission enforcement ───────────────────────────────────────────────

    public function test_admin_view_only_cannot_create_blog(): void
    {
        $admin = $this->adminWithRole('admin');

        Livewire::actingAs($admin, 'admin')
            ->test(CreateBlog::class)
            ->assertForbidden();
    }

    public function test_admin_view_only_cannot_delete_page(): void
    {
        $admin = $this->adminWithRole('admin');
        $page = Page::factory()->create();

        $this->assertFalse($admin->can('delete', $page));
    }

    public function test_content_manager_can_delete_content(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $page = Page::factory()->create();

        $this->assertTrue($admin->can('delete', $page));
    }

    public function test_super_admin_can_delete_content(): void
    {
        $admin = $this->superAdmin();
        $page = Page::factory()->create();

        $this->assertTrue($admin->can('delete', $page));
    }

    public function test_no_admin_resource_or_role_resource_exists(): void
    {
        $resources = Filament::getPanel('admin')->getResources();
        $resourceClasses = array_map(fn ($r): string => class_basename($r), $resources);

        $this->assertNotContains('AdminResource', $resourceClasses);
        $this->assertNotContains('RoleResource', $resourceClasses);
    }

    // ─── PageResource deletion (Phase 3I) ────────────────────────────────────

    public function test_page_resource_no_longer_exists_as_a_registered_resource(): void
    {
        $resources = Filament::getPanel('admin')->getResources();
        $resourceClasses = array_map(fn ($r): string => class_basename($r), $resources);

        $this->assertNotContains('PageResource', $resourceClasses);
    }

    public function test_page_resource_class_does_not_exist(): void
    {
        $this->assertFalse(class_exists(PageResource::class));
    }
}
