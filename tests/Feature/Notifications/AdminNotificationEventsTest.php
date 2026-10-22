<?php

namespace Tests\Feature\Notifications;

use App\Enums\AccountStatus;
use App\Events\ContactMessageReceived;
use App\Events\StudentRegistered;
use App\Jobs\Notifications\SendFcmMessage;
use App\Models\Admin;
use App\Models\FcmToken;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminNotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_form_submission_dispatches_event_and_notifies_active_admins(): void
    {
        Queue::fake([SendFcmMessage::class]);

        $activeAdmin = Admin::factory()->create(['status' => AccountStatus::Active, 'locale' => 'en']);
        $suspendedAdmin = Admin::factory()->suspended()->create();

        FcmToken::create([
            'tokenable_type' => 'admin',
            'tokenable_id' => $activeAdmin->id,
            'token' => 'admin-fcm-device-token-123',
            'device_type' => 'web',
            'is_active' => true,
        ]);

        $payload = [
            'name' => 'Dr. Sarah',
            'email' => 'sarah@example.com',
            'subject' => 'Inquiry about medical lectures',
            'message' => 'Hello, I would like to inquire about the fellowship courses.',
        ];

        $response = $this->postJson('/api/public/contact', $payload);
        $response->assertStatus(201);

        // Check active admin got database notification
        $this->assertSame(1, $activeAdmin->notifications()->count());
        $notification = $activeAdmin->notifications()->first();
        $this->assertSame('contact', $notification->data['category']);
        $this->assertSame('contact_message', $notification->data['type']);
        $this->assertStringContainsString('Dr. Sarah', $notification->data['body']);

        // Check suspended admin did not get notified
        $this->assertSame(0, $suspendedAdmin->notifications()->count());

        // Check FCM push job was dispatched to active admin's token
        Queue::assertPushed(SendFcmMessage::class, function (SendFcmMessage $job) {
            return $job->data['type'] === 'contact_message'
                && $job->data['category'] === 'contact';
        });
    }

    public function test_student_registration_dispatches_event_and_notifies_active_admins(): void
    {
        Queue::fake([SendFcmMessage::class]);

        $activeAdmin = Admin::factory()->create(['status' => AccountStatus::Active, 'locale' => 'en']);

        $user = User::factory()->create([
            'name' => 'Student John',
            'email' => 'john@example.com',
        ]);

        event(new StudentRegistered($user));

        $this->assertSame(1, $activeAdmin->notifications()->count());
        $notification = $activeAdmin->notifications()->first();
        $this->assertSame('subscription', $notification->data['category']);
        $this->assertSame('new_subscription', $notification->data['type']);
        $this->assertStringContainsString('Student John', $notification->data['body']);
    }

    public function test_student_subscribed_event_notifies_active_admins(): void
    {
        $activeAdmin = Admin::factory()->create(['status' => AccountStatus::Active, 'locale' => 'ar']);

        $user = User::factory()->create([
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
        ]);

        event(new \App\Events\StudentSubscribed($user, 'Medical Fellowship Pro'));

        $this->assertSame(1, $activeAdmin->notifications()->count());
        $notification = $activeAdmin->notifications()->first();
        $this->assertSame('subscription', $notification->data['category']);
        $this->assertSame('new_subscription', $notification->data['type']);
        $this->assertStringContainsString('Ahmed Ali', $notification->data['body']);
    }

    public function test_notification_resource_returns_custom_type_for_frontend(): void
    {
        $user = User::factory()->create();

        $user->notify(new InAppNotification(
            category: 'subscription',
            title: 'Welcome',
            body: 'Welcome aboard',
            type: 'new_subscription',
        ));

        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/api/student/notifications');
        $response->assertOk()
            ->assertJsonPath('data.0.type', 'new_subscription')
            ->assertJsonPath('data.0.category', 'subscription');
    }
}
