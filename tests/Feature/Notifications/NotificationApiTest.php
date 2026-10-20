<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private string $indexUrl = '/api/student/notifications';
    private string $unreadCountUrl = '/api/student/notifications/unread-count';
    private string $readAllUrl = '/api/student/notifications/read-all';

    public function test_guest_cannot_view_or_mark_notifications(): void
    {
        $this->getJson($this->indexUrl)->assertUnauthorized();
        $this->getJson($this->unreadCountUrl)->assertUnauthorized();
        $this->patchJson($this->readAllUrl)->assertUnauthorized();
    }

    public function test_user_can_view_notifications_and_unread_count(): void
    {
        $user = User::factory()->create();

        $user->notify(new InAppNotification(
            category: 'general',
            title: 'Welcome Title',
            body: 'Welcome to Z-Medix!',
            actionUrl: '/study/subjects',
        ));

        $user->notify(new InAppNotification(
            category: 'quiz',
            title: 'Quiz Result',
            body: 'Your quiz result is available.',
        ));

        Sanctum::actingAs($user);

        $response = $this->getJson($this->indexUrl);
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $countResponse = $this->getJson($this->unreadCountUrl);
        $countResponse->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_user_can_mark_single_notification_as_read(): void
    {
        $user = User::factory()->create();

        $user->notify(new InAppNotification(
            category: 'general',
            title: 'Test Note',
            body: 'Body text',
        ));

        $notification = $user->notifications()->first();

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/student/notifications/{$notification->id}/read");
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_read', true);

        $this->assertNotNull($notification->fresh()->read_at);

        $countResponse = $this->getJson($this->unreadCountUrl);
        $countResponse->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_user_can_mark_all_notifications_as_read(): void
    {
        $user = User::factory()->create();

        $user->notify(new InAppNotification(category: 'one', title: 'T1', body: 'B1'));
        $user->notify(new InAppNotification(category: 'two', title: 'T2', body: 'B2'));

        Sanctum::actingAs($user);

        $response = $this->patchJson($this->readAllUrl);
        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_user_can_filter_notifications_by_unread_and_category(): void
    {
        $user = User::factory()->create();

        $user->notify(new InAppNotification(category: 'quiz', title: 'Quiz 1', body: 'B1'));
        $user->notify(new InAppNotification(category: 'study', title: 'Study 1', body: 'B2'));

        $readNotification = $user->notifications()->first();
        $readNotification->markAsRead();

        Sanctum::actingAs($user);

        // Filter unread only
        $response = $this->getJson("{$this->indexUrl}?unread_only=true");
        $response->assertOk()
            ->assertJsonCount(1, 'data');

        // Filter by category
        $catResponse = $this->getJson("{$this->indexUrl}?category=quiz");
        $catResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'quiz');
    }
}
