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
        $admin->givePermissionTo($canEdit ? ['content.view', 'content.update', 'content.publish'] : ['content.view']);

        return $admin;
    }

    public function test_policy_is_editable_using_the_same_translated_rich_text_form(): void
    {
        $section = $this->section();
        $section->update(['status' => ContentStatus::Published]);
        $html = '<h2>Subscriptions</h2><p><strong>Manual payment</strong> terms.</p>';
        Livewire::actingAs($this->admin(true), 'admin')->test(EditPageSection::class, ['record' => $section->id])
            ->assertOk()->fillForm([
                'data.title.en' => 'Subscription Policy', 'data.title.ar' => 'سياسة الاشتراك',
                'data.body.en' => $html, 'data.body.ar' => '<p>سياسة الاشتراك</p>',
            ])->call('save')->assertHasNoFormErrors()->assertNotified();
        $this->assertSame($html, $section->refresh()->data['body']['en']);
        $this->getJson('/api/public/pages/subscription-policy')->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('data.key', 'subscription-policy')
            ->assertJsonPath('data.content', $html);
        $this->getJson('/api/public/pages/subscription-policy?lang=ar')->assertOk()
            ->assertJsonPath('data.content', '<p>سياسة الاشتراك</p>');
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
    }
}
