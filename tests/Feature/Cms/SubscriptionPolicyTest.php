<?php

namespace Tests\Feature\Cms;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Enums\ContentStatus;
use App\Filament\Pages\WebsiteContentPage;
use App\Filament\Resources\PageSectionResource\Pages\EditPageSection;
use App\Models\Admin;
use App\Models\Page;
use App\Models\PageSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SubscriptionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function section(): PageSection
    {
        return PageSection::query()->whereHas('page', fn ($query) => $query->where('key', 'subscription-policy'))->firstOrFail();
    }

    private function admin(bool $canEdit): Admin
    {
        foreach (['content.view', 'content.update', 'content.publish'] as $permission) {
            Permission::findOrCreate($permission, 'admin');
        }
        $admin = Admin::factory()->create(['status' => AccountStatus::Active, 'type' => AdminType::Admin]);
        $admin->givePermissionTo($canEdit ? ['content.view', 'content.update'] : ['content.view']);

        return $admin;
    }

    public function test_policy_is_editable_using_the_same_translated_rich_text_form(): void
    {
        $section = $this->section();
        $section->update(['status' => ContentStatus::Published, 'data' => ['title' => ['en' => 'Subscription Policy', 'ar' => 'سياسة الاشتراك'], 'body' => ['en' => '', 'ar' => '<p>سياسة الاشتراك</p>']]]);
        $html = '<h2>Subscriptions</h2><p><strong>Manual payment</strong> terms.</p>';
        Livewire::actingAs($this->admin(true), 'admin')->test(EditPageSection::class, ['record' => $section->id])
            ->assertOk()->fillForm([
                'data.title.en' => 'Subscription Policy',
                'data.body.en' => $html,
            ])->call('save')->assertHasNoFormErrors()->assertNotified();
        $this->assertSame($html, $section->refresh()->data['body']['en']);
        $this->getJson('/api/public/pages/subscription-policy')->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('data.key', 'subscription-policy')
            ->assertJsonPath('data.content', $html);
        $this->getJson('/api/public/pages/subscription-policy?lang=ar')->assertOk()
            ->assertJsonPath('data.content', '<p>سياسة الاشتراك</p>');
    }

    public function test_saving_draft_policy_automatically_publishes_translated_html_and_subsequent_updates(): void
    {
        $section = $this->section();
        $admin = $this->admin(true);
        $html = '<h2>Saved policy</h2><p><strong>Payment terms</strong></p>';
        $arabic = '<p>شروط الاشتراك</p>';
        $section->update(['data' => ['title' => ['en' => 'Subscription Policy', 'ar' => 'سياسة الاشتراك'], 'body' => ['en' => '', 'ar' => $arabic]]]);

        $this->assertSame(ContentStatus::Draft, $section->status);
        $this->assertFalse($admin->hasPermissionTo('content.publish'));
        $this->getJson('/api/public/pages/subscription-policy')->assertOk()
            ->assertJsonPath('data.content', null)->assertJsonCount(0, 'data.sections');

        $editor = Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->id])
            ->assertActionDoesNotExist('publish')
            ->fillForm([
                'data.body.en' => $html,
            ])->call('save')->assertHasNoFormErrors()->assertNotified();

        $this->assertSame(ContentStatus::Published, $section->refresh()->status);
        $this->assertSame($admin->id, $section->updated_by_admin_id);
        $this->assertSame($html, $section->data['body']['en']);
        $this->assertSame($arabic, $section->data['body']['ar']);
        $this->getJson('/api/public/pages/subscription-policy')->assertOk()
            ->assertJsonPath('data.content', $html)->assertJsonPath('data.sections.0.body', $html);
        $this->getJson('/api/public/pages/subscription-policy?lang=ar')->assertOk()
            ->assertJsonPath('data.content', $arabic);

        $updatedHtml = '<h2>Updated policy</h2><p><em>Latest terms</em></p>';
        $editor->fillForm(['data.body.en' => $updatedHtml])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame(ContentStatus::Published, $section->refresh()->status);
        $this->assertSame($updatedHtml, $section->data['body']['en']);
        $this->assertSame($arabic, $section->data['body']['ar']);
        $this->getJson('/api/public/pages/subscription-policy')->assertOk()
            ->assertJsonPath('data.content', $updatedHtml)->assertJsonPath('data.sections.0.body', $updatedHtml);
        $this->getJson('/api/public/pages/subscription-policy?lang=ar')->assertOk()
            ->assertJsonPath('data.content', $arabic);
    }

    public function test_resaving_existing_unpublished_policy_preserves_its_html_and_publishes_it(): void
    {
        $section = $this->section();
        $data = $section->data;
        $data['body'] = ['en' => '<p><strong>Existing terms</strong></p>', 'ar' => '<p>الشروط الحالية</p>'];

        foreach ([ContentStatus::Draft, ContentStatus::Archived] as $status) {
            $section->update(['data' => $data, 'status' => $status]);

            Livewire::actingAs($this->admin(true), 'admin')
                ->test(EditPageSection::class, ['record' => $section->id])
                ->call('save')->assertHasNoFormErrors();

            $this->assertSame(ContentStatus::Published, $section->refresh()->status);
            $this->assertSame($data['body']['en'], $section->data['body']['en']);
            $this->assertSame($data['body']['ar'], $section->data['body']['ar']);
            $this->getJson('/api/public/pages/subscription-policy')->assertOk()
                ->assertJsonPath('data.content', $data['body']['en']);
            $this->getJson('/api/public/pages/subscription-policy?lang=ar')->assertOk()
                ->assertJsonPath('data.content', $data['body']['ar']);
        }
    }

    public function test_revoking_edit_permission_prevents_saving_and_publication(): void
    {
        $section = $this->section();
        $admin = $this->admin(true);
        $editor = Livewire::actingAs($admin, 'admin')
            ->test(EditPageSection::class, ['record' => $section->id])
            ->fillForm(['data.body.en' => '<p>Unauthorized terms</p>']);

        $admin->revokePermissionTo('content.update');
        $editor->call('save')->assertForbidden();

        $this->assertSame(ContentStatus::Draft, $section->refresh()->status);
        $this->assertSame('', $section->data['body']['en']);
    }

    public function test_saving_other_legal_pages_preserves_their_publication_status(): void
    {
        $admin = $this->admin(true);

        foreach (['privacy-policy', 'terms-and-conditions'] as $key) {
            $page = Page::factory()->create(['key' => $key, 'status' => ContentStatus::Published]);
            $section = PageSection::factory()->create([
                'page_id' => $page->id,
                'section_key' => 'content',
                'status' => ContentStatus::Draft,
            ]);

            foreach ([ContentStatus::Draft, ContentStatus::Archived] as $status) {
                $section->update(['status' => $status]);
                Livewire::actingAs($admin, 'admin')->test(EditPageSection::class, ['record' => $section->id])
                    ->fillForm(['data.body.en' => '<p>Updated terms</p>'])
                    ->call('save')->assertHasNoFormErrors();

                $this->assertSame($status, $section->refresh()->status);
                $this->assertSame('<p>Updated terms</p>', $section->data['body']['en']);
                $this->getJson('/api/public/pages/'.$key)->assertOk()->assertJsonPath('data.content', null);
            }
        }
    }

    public function test_policy_is_available_in_website_content_next_to_existing_legal_pages(): void
    {
        $component = Livewire::actingAs($this->admin(true), 'admin')->test(WebsiteContentPage::class)->assertOk();
        $cards = collect($component->instance()->getCards())->keyBy('key');
        foreach (['privacy-policy', 'terms-and-conditions', 'subscription-policy'] as $key) {
            $this->assertArrayHasKey($key, $cards->all());
            $this->assertFalse($cards[$key]['disabled']);
        }
    }

    public function test_missing_draft_and_empty_content_follow_existing_page_behavior(): void
    {
        $this->getJson('/api/public/pages/subscription-policy')->assertOk()->assertJsonPath('data.content', null);
        $section = $this->section();
        $section->update(['status' => ContentStatus::Published]);
        $this->getJson('/api/public/pages/subscription-policy')->assertOk()->assertJsonPath('data.content', '');
        $section->page->update(['status' => ContentStatus::Draft]);
        $this->getJson('/api/public/pages/subscription-policy')->assertNotFound();
        $section->page->delete();
        $this->getJson('/api/public/pages/subscription-policy')->assertNotFound();
    }

    public function test_provisioning_reuses_records_and_preserves_all_existing_content(): void
    {
        foreach (['privacy-policy', 'terms-and-conditions'] as $key) {
            $page = Page::factory()->create(['key' => $key, 'status' => ContentStatus::Published]);
            PageSection::factory()->create(['page_id' => $page->id, 'section_key' => 'content', 'status' => ContentStatus::Published,
                'data' => ['body' => ['en' => '<p>'.$key.'</p>']]]);
        }
        $section = $this->section();
        $section->update(['data' => ['body' => ['en' => '<p>Existing policy</p>']], 'status' => ContentStatus::Published]);
        $migration = require glob(database_path('migrations/*add_subscription_policy_content.php'))[0];
        $migration->up();
        $migration->down();
        $this->assertSame('<p>Existing policy</p>', $section->refresh()->data['body']['en']);
        $this->assertSame(1, Page::where('key', 'subscription-policy')->count());
        $this->assertSame(1, PageSection::where('page_id', $section->page_id)->count());
        foreach (['privacy-policy', 'terms-and-conditions'] as $key) {
            $this->getJson('/api/public/pages/'.$key)->assertOk()->assertJsonPath('data.content', '<p>'.$key.'</p>');
        }
    }

    public function test_admin_without_edit_permission_cannot_edit_policy(): void
    {
        $section = $this->section();
        Livewire::actingAs($this->admin(false), 'admin')->test(EditPageSection::class, ['record' => $section->id])->assertForbidden();
        $this->assertSame('', $section->refresh()->data['body']['en']);
        $this->assertSame(ContentStatus::Draft, $section->status);
    }
}
