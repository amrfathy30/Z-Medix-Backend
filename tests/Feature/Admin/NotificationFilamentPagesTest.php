<?php

namespace Tests\Feature\Admin;

use App\Enums\NotificationPriority;
use App\Enums\NotificationTemplateStatus;
use App\Filament\Pages\NotificationSettingsPage;
use App\Filament\Pages\SendNotificationPage;
use App\Filament\Resources\FcmTokenResource\Pages\ListFcmTokens;
use App\Filament\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Models\Admin;
use App\Models\FcmToken;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notifications\FcmTopicService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class NotificationFilamentPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function superAdmin(): Admin
    {
        $admin = Admin::factory()->superAdmin()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_notification_settings_page_mounts_and_saves_operational_settings(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(NotificationSettingsPage::class)
            ->assertSuccessful()
            ->set('data.fcm_enabled', true)
            ->set('data.in_app_enabled', true)
            ->set('data.notification_topics', ['all_students', 'exams_2026'])
            ->set('data.fcm_web_vapid_key', 'test-vapid-key')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified(__('notifications.settings.saved'));

        $this->assertDatabaseHas('settings', [
            'key' => NotificationSettingsPage::SETTING_VAPID_KEY,
            'value' => 'test-vapid-key',
            'group' => 'notifications',
        ]);
    }

    public function test_send_notification_page_mounts_successfully(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(SendNotificationPage::class)
            ->assertSuccessful();
    }

    public function test_send_notification_page_dispatches_topic_push(): void
    {
        $mockTopicService = Mockery::mock(FcmTopicService::class);
        $mockTopicService->shouldReceive('sendToTopic')
            ->once()
            ->withArgs(function ($topic, $title, $body, $data) {
                return $topic === 'announcements'
                    && $title === 'Important Announcement'
                    && $body === 'Classes will start tomorrow';
            })
            ->andReturn(['name' => 'projects/test/messages/123']);

        $this->app->instance(FcmTopicService::class, $mockTopicService);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(SendNotificationPage::class)
            ->set('data.target_type', 'topic')
            ->set('data.topic', 'announcements')
            ->set('data.channels', ['fcm'])
            ->set('data.title', 'Important Announcement')
            ->set('data.body', 'Classes will start tomorrow')
            ->call('send')
            ->assertHasNoErrors()
            ->assertNotified(__('notifications.send_page.sent_success', ['count' => 1]));
    }

    public function test_notification_templates_resource_renders_list(): void
    {
        NotificationTemplate::create([
            'key' => 'welcome_student',
            'title' => ['en' => 'Welcome', 'ar' => 'أهلاً بك'],
            'body' => ['en' => 'Welcome to Z-Medix', 'ar' => 'مرحباً بك في زد ميديكس'],
            'channels' => ['fcm', 'in_app'],
            'priority' => NotificationPriority::Normal,
            'status' => NotificationTemplateStatus::Active,
        ]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListNotificationTemplates::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(NotificationTemplate::all());
    }

    public function test_fcm_tokens_resource_renders_list(): void
    {
        $user = User::factory()->create();

        $token = FcmToken::create([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'token' => 'sample-test-token-string-12345',
            'device_type' => 'android',
            'app_type' => 'student',
            'is_active' => true,
        ]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListFcmTokens::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$token]);
    }
}
