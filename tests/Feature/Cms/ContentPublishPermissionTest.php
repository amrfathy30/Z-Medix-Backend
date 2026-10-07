<?php

namespace Tests\Feature\Cms;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Enums\ContentStatus;
use App\Filament\Resources\BlogCategoryResource;
use App\Filament\Resources\BlogCategoryResource\Pages\EditBlogCategory;
use App\Filament\Resources\BlogResource;
use App\Filament\Resources\BlogResource\Pages\CreateBlog;
use App\Filament\Resources\BlogResource\Pages\EditBlog;
use App\Filament\Resources\FaqCategoryResource;
use App\Filament\Resources\FaqCategoryResource\Pages\EditFaqCategory;
use App\Filament\Resources\FaqResource;
use App\Filament\Resources\FaqResource\Pages\CreateFaq;
use App\Filament\Resources\FaqResource\Pages\EditFaq;
use App\Filament\Resources\PageSectionItemResource;
use App\Filament\Resources\PageSectionItemResource\Pages\EditPageSectionItem;
use App\Models\Admin;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\PageSectionItem;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * content.publish is its own permission, so the publish/archive actions are
 * offered only to a role that actually holds it — not to every role that may
 * edit content.
 */
class ContentPublishPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesPermissionsSeeder::class);
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

    /**
     * An editor-shaped role: full content editing, no publishing. Built from the
     * seeded content.* permissions rather than by redefining the role model.
     */
    private function editorWithoutPublishPermission(): Admin
    {
        $role = Role::firstOrCreate(
            ['name' => 'content_editor_no_publish', 'guard_name' => 'admin'],
            ['display_name_ar' => 'محرر بدون نشر', 'display_name_en' => 'Editor (no publishing)'],
        );
        $role->syncPermissions(['content.view', 'content.create', 'content.update']);

        return $this->adminWithRole('content_editor_no_publish');
    }

    public function test_an_editor_without_content_publish_is_not_offered_publish_or_archive(): void
    {
        $draft = Blog::factory()->draft()->create();

        Livewire::actingAs($this->editorWithoutPublishPermission(), 'admin')
            ->test(EditBlog::class, ['record' => $draft->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('publish');

        $published = Blog::factory()->create([
            'status' => ContentStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        Livewire::actingAs($this->editorWithoutPublishPermission(), 'admin')
            ->test(EditBlog::class, ['record' => $published->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('archive');

        $this->assertSame(ContentStatus::Draft, $draft->refresh()->status);
    }

    // ─── Blog form ──────────────────────────────────────────────────────────

    public function test_an_editor_without_content_publish_cannot_publish_through_the_create_form(): void
    {
        Livewire::actingAs($this->editorWithoutPublishPermission(), 'admin')
            ->test(CreateBlog::class)
            ->fillForm([
                'title.en' => 'Smuggled Post',
                'slug' => 'smuggled-post',
                'status' => ContentStatus::Published->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['status']);

        $this->assertDatabaseMissing('blogs', ['slug' => 'smuggled-post']);
    }

    public function test_an_editor_without_content_publish_cannot_publish_through_the_edit_form(): void
    {
        $blog = Blog::factory()->draft()->create();

        Livewire::actingAs($this->editorWithoutPublishPermission(), 'admin')
            ->test(EditBlog::class, ['record' => $blog->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(ContentStatus::Draft, $blog->refresh()->status);
    }

    public function test_an_editor_without_content_publish_may_still_save_other_blog_fields(): void
    {
        $blog = Blog::factory()->draft()->create();

        Livewire::actingAs($this->editorWithoutPublishPermission(), 'admin')
            ->test(EditBlog::class, ['record' => $blog->getRouteKey()])
            ->fillForm(['title.en' => 'Edited Without Publishing'])
            ->call('save')
            ->assertHasNoFormErrors();

        $blog->refresh();

        $this->assertSame('Edited Without Publishing', $blog->getTranslation('title', 'en'));
        $this->assertSame(ContentStatus::Draft, $blog->status);
    }

    public function test_an_already_published_post_stays_published_when_an_editor_saves_other_fields(): void
    {
        $blog = Blog::factory()->create([
            'status' => ContentStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        Livewire::actingAs($this->editorWithoutPublishPermission(), 'admin')
            ->test(EditBlog::class, ['record' => $blog->getRouteKey()])
            ->assertOk()
            ->assertFormFieldDisabled('status')
            ->fillForm(['title.en' => 'Edited While Published'])
            ->call('save')
            ->assertHasNoFormErrors();

        $blog->refresh();

        $this->assertSame('Edited While Published', $blog->getTranslation('title', 'en'));
        $this->assertSame(ContentStatus::Published, $blog->status);
    }

    public function test_a_publisher_can_publish_through_the_edit_form(): void
    {
        $blog = Blog::factory()->draft()->create();

        Livewire::actingAs($this->adminWithRole('content_manager'), 'admin')
            ->test(EditBlog::class, ['record' => $blog->getRouteKey()])
            ->assertFormFieldEnabled('status')
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ContentStatus::Published, $blog->refresh()->status);
    }

    // ─── Every content resource with a publishable status ───────────────────

    /**
     * @return array<string, array{class-string, class-string<Model>}>
     */
    public static function publishableResources(): array
    {
        return [
            'blog' => [BlogResource::class, Blog::class],
            'blog category' => [BlogCategoryResource::class, BlogCategory::class],
            'faq' => [FaqResource::class, Faq::class],
            'faq category' => [FaqCategoryResource::class, FaqCategory::class],
            'page section item' => [PageSectionItemResource::class, PageSectionItem::class],
        ];
    }

    /**
     * @param  class-string  $resource
     * @param  class-string<Model>  $model
     */
    #[DataProvider('publishableResources')]
    public function test_published_is_not_an_available_status_without_the_publish_permission(string $resource, string $model): void
    {
        $this->actingAs($this->editorWithoutPublishPermission(), 'admin');

        // The options are also what Filament validates the submission against,
        // so dropping Published here is what rejects a crafted payload.
        $this->assertArrayNotHasKey(ContentStatus::Published->value, $resource::publishableStatusOptions());
        $this->assertArrayHasKey(ContentStatus::Draft->value, $resource::publishableStatusOptions());
        $this->assertNotSame(
            ContentStatus::Published->value,
            $resource::defaultPublishableStatus(ContentStatus::Published),
        );
    }

    /**
     * @param  class-string  $resource
     * @param  class-string<Model>  $model
     */
    #[DataProvider('publishableResources')]
    public function test_published_is_available_with_the_publish_permission(string $resource, string $model): void
    {
        $this->actingAs($this->adminWithRole('content_manager'), 'admin');

        $this->assertArrayHasKey(ContentStatus::Published->value, $resource::publishableStatusOptions());
        $this->assertSame(
            ContentStatus::Published->value,
            $resource::defaultPublishableStatus(ContentStatus::Published),
        );
    }

    /**
     * @param  class-string  $resource
     * @param  class-string<Model>  $model
     */
    #[DataProvider('publishableResources')]
    public function test_the_shared_guard_refuses_publishing_without_the_permission(string $resource, string $model): void
    {
        $this->actingAs($this->editorWithoutPublishPermission(), 'admin');

        $published = $model::factory()->create(['status' => ContentStatus::Published]);
        $draft = $model::factory()->create(['status' => ContentStatus::Draft]);

        // Already published: frozen, and leaving Published is refused too.
        $this->assertTrue($resource::isStatusLocked($published));
        $this->assertFalse($resource::isStatusLocked($draft));

        // An unchanged status, and a move between two unpublished states, are
        // ordinary edits and must not be blocked.
        $resource::assertMayApplyStatus(['status' => ContentStatus::Published->value], $published);
        $resource::assertMayApplyStatus(['status' => ContentStatus::Archived->value], $draft);

        $this->expectException(Halt::class);
        $resource::assertMayApplyStatus(['status' => ContentStatus::Published->value], $draft);
    }

    /**
     * @param  class-string  $resource
     * @param  class-string<Model>  $model
     */
    #[DataProvider('publishableResources')]
    public function test_unpublishing_is_refused_without_the_publish_permission(string $resource, string $model): void
    {
        $this->actingAs($this->editorWithoutPublishPermission(), 'admin');

        $published = $model::factory()->create(['status' => ContentStatus::Published]);

        $this->expectException(Halt::class);

        $resource::assertMayApplyStatus(['status' => ContentStatus::Draft->value], $published);
    }

    public function test_an_editor_cannot_publish_a_faq_through_its_form(): void
    {
        $editor = $this->editorWithoutPublishPermission();
        $faq = Faq::factory()->create(['status' => ContentStatus::Draft]);

        Livewire::actingAs($editor, 'admin')
            ->test(EditFaq::class, ['record' => $faq->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(ContentStatus::Draft, $faq->refresh()->status);
    }

    public function test_an_editor_cannot_publish_a_faq_category_through_its_form(): void
    {
        $category = FaqCategory::factory()->create(['status' => ContentStatus::Draft]);

        Livewire::actingAs($this->editorWithoutPublishPermission(), 'admin')
            ->test(EditFaqCategory::class, ['record' => $category->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(ContentStatus::Draft, $category->refresh()->status);
    }

    public function test_an_editor_cannot_publish_a_blog_category_through_its_form(): void
    {
        $category = BlogCategory::factory()->create(['status' => ContentStatus::Draft]);

        Livewire::actingAs($this->editorWithoutPublishPermission(), 'admin')
            ->test(EditBlogCategory::class, ['record' => $category->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(ContentStatus::Draft, $category->refresh()->status);
    }

    public function test_an_editor_cannot_publish_a_page_section_item_through_its_form(): void
    {
        $item = PageSectionItem::factory()->create(['status' => ContentStatus::Draft]);

        Livewire::actingAs($this->editorWithoutPublishPermission(), 'admin')
            ->test(EditPageSectionItem::class, ['record' => $item->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(ContentStatus::Draft, $item->refresh()->status);
    }

    public function test_an_editor_can_still_create_a_faq_which_defaults_to_published(): void
    {
        // The Faq form defaults to Published; for an editor who cannot publish
        // that default falls back to Draft so creating stays possible.
        Livewire::actingAs($this->editorWithoutPublishPermission(), 'admin')
            ->test(CreateFaq::class)
            ->fillForm([
                'question.en' => 'Can an editor still create?',
                'answer.en' => 'Yes, as a draft.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $faq = Faq::query()->latest('id')->first();

        $this->assertNotNull($faq);
        $this->assertSame(ContentStatus::Draft, $faq->status);
    }

    public function test_a_content_manager_can_still_publish_a_faq(): void
    {
        $faq = Faq::factory()->create(['status' => ContentStatus::Draft]);

        Livewire::actingAs($this->adminWithRole('content_manager'), 'admin')
            ->test(EditFaq::class, ['record' => $faq->getRouteKey()])
            ->fillForm(['status' => ContentStatus::Published->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ContentStatus::Published, $faq->refresh()->status);
    }

    public function test_the_status_guard_refuses_a_payload_that_skips_the_form(): void
    {
        $this->actingAs($this->editorWithoutPublishPermission(), 'admin');

        $this->expectException(Halt::class);

        BlogResource::assertMayApplyStatus(['status' => ContentStatus::Published->value]);
    }

    public function test_the_status_guard_allows_a_publisher(): void
    {
        $this->actingAs($this->adminWithRole('content_manager'), 'admin');

        BlogResource::assertMayApplyStatus(['status' => ContentStatus::Published->value]);

        $this->assertTrue(BlogResource::canPublishContent());
    }

    public function test_a_content_manager_holding_content_publish_can_publish_and_archive(): void
    {
        $admin = $this->adminWithRole('content_manager');
        $blog = Blog::factory()->draft()->create();

        Livewire::actingAs($admin, 'admin')
            ->test(EditBlog::class, ['record' => $blog->getRouteKey()])
            ->assertActionVisible('publish')
            ->callAction('publish');

        $this->assertSame(ContentStatus::Published, $blog->refresh()->status);

        Livewire::actingAs($admin, 'admin')
            ->test(EditBlog::class, ['record' => $blog->getRouteKey()])
            ->assertActionVisible('archive')
            ->callAction('archive');

        $this->assertSame(ContentStatus::Archived, $blog->refresh()->status);
    }
}
