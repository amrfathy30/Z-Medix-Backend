<?php

namespace Tests\Feature\Notifications;

use App\Enums\BillingCycle;
use App\Enums\ContactMessageStatus;
use App\Events\ContactMessageReplied;
use App\Events\SubscriptionApproved;
use App\Events\SubscriptionRejected;
use App\Jobs\Notifications\SendFcmMessage;
use App\Models\ContactMessage;
use App\Models\FcmToken;
use App\Models\Plan;
use App\Models\SubscriptionPeriod;
use App\Models\User;
use Database\Factories\SubscriptionPeriodFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StudentNotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_approved_event_sends_notification_with_type(): void
    {
        Queue::fake([SendFcmMessage::class]);

        $student = User::factory()->create();
        $plan = Plan::create([
            'name' => 'Ultra Fellowship',
            'slug' => 'ultra-fellowship',
            'prices' => [BillingCycle::Monthly->value => 50],
            'is_active' => true,
        ]);

        FcmToken::create([
            'tokenable_type' => 'user',
            'tokenable_id' => $student->id,
            'token' => 'student-fcm-device-token-123',
            'device_type' => 'android',
            'is_active' => true,
        ]);

        $subscription = \App\Models\StudentSubscription::create(['user_id' => $student->id]);
        $period = $subscription->periods()->create([
            'plan_id' => $plan->id,
            'billing_cycle' => BillingCycle::Monthly,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'amount' => 50,
            'currency' => 'USD',
            'paid_at' => now(),
        ]);

        event(new SubscriptionApproved($student, $plan, $period));

        $this->assertSame(1, $student->notifications()->count());
        $notification = $student->notifications()->first();

        $this->assertSame('subscription', $notification->data['category']);
        $this->assertSame('subscription_approved', $notification->data['type']);
        $this->assertStringContainsString('Ultra Fellowship', $notification->data['body']);

        // Check FCM push job was dispatched to student
        Queue::assertPushed(SendFcmMessage::class, function (SendFcmMessage $job) {
            return $job->data['type'] === 'subscription_approved'
                && $job->data['category'] === 'subscription';
        });

        // Test API response reflects type
        $this->actingAs($student, 'sanctum');
        $response = $this->getJson('/api/student/notifications');
        $response->assertOk()
            ->assertJsonPath('data.0.type', 'subscription_approved');
    }

    public function test_subscription_rejected_event_sends_notification_with_type(): void
    {
        $student = User::factory()->create();
        $plan = Plan::create([
            'name' => 'Basic Plan',
            'slug' => 'basic-plan',
            'prices' => [BillingCycle::Monthly->value => 20],
            'is_active' => true,
        ]);

        event(new SubscriptionRejected($student, $plan, 'Payment screenshot is unreadable'));

        $this->assertSame(1, $student->notifications()->count());
        $notification = $student->notifications()->first();

        $this->assertSame('subscription', $notification->data['category']);
        $this->assertSame('subscription_rejected', $notification->data['type']);
        $this->assertStringContainsString('Payment screenshot is unreadable', $notification->data['body']);
    }

    public function test_contact_message_replied_event_notifies_registered_student(): void
    {
        $student = User::factory()->create([
            'email' => 'doctor@example.com',
        ]);

        $contact = ContactMessage::create([
            'name' => 'Dr. Mona',
            'email' => 'doctor@example.com',
            'subject' => 'Fellowship Question Bank',
            'message' => 'How can I access the Cardiology question bank?',
            'status' => ContactMessageStatus::Replied,
            'admin_reply' => 'The Cardiology question bank is available under Ultra plan.',
            'replied_at' => now(),
        ]);

        event(new ContactMessageReplied($contact));

        $this->assertSame(1, $student->notifications()->count());
        $notification = $student->notifications()->first();

        $this->assertSame('contact', $notification->data['category']);
        $this->assertSame('contact_replied', $notification->data['type']);

        $this->actingAs($student, 'sanctum');
        $response = $this->getJson('/api/student/notifications');
        $response->assertOk()
            ->assertJsonPath('data.0.type', 'contact_replied');
    }
}
