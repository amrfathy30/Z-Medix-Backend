<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Services\Notifications\FcmTopicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class FcmTopicApiTest extends TestCase
{
    use RefreshDatabase;

    private string $subscribeUrl = '/api/student/fcm-tokens/topic/subscribe';
    private string $unsubscribeUrl = '/api/student/fcm-tokens/topic/unsubscribe';
    private Mockery\MockInterface $messagingMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->messagingMock = Mockery::mock(Messaging::class);
        $this->app->instance(Messaging::class, $this->messagingMock);
    }

    public function test_guest_cannot_subscribe_or_unsubscribe_from_topics(): void
    {
        $this->postJson($this->subscribeUrl, ['topic' => 'news'])->assertUnauthorized();
        $this->postJson($this->unsubscribeUrl, ['topic' => 'news'])->assertUnauthorized();
    }

    public function test_topic_name_is_required_and_validated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson($this->subscribeUrl, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topic']);

        $this->postJson($this->subscribeUrl, ['topic' => 'invalid topic spaces!'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topic']);
    }

    public function test_returns_error_when_subscribing_with_no_active_tokens(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson($this->subscribeUrl, ['topic' => 'announcements'])
            ->assertBadRequest()
            ->assertJsonPath('success', false);
    }

    public function test_subscribes_explicit_token_to_topic(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->messagingMock->shouldReceive('subscribeToTopic')
            ->once()
            ->with('announcements', ['custom-device-token'])
            ->andReturn(['successCount' => 1]);

        $response = $this->postJson($this->subscribeUrl, [
            'topic' => 'announcements',
            'token' => 'custom-device-token',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.topic', 'announcements')
            ->assertJsonPath('data.tokens_count', 1);
    }

    public function test_unsubscribes_user_active_tokens_from_topic(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/student/fcm-tokens', [
            'token' => 'active-token-1',
            'device_id' => 'device-1',
        ])->assertCreated();

        $this->postJson('/api/student/fcm-tokens', [
            'token' => 'active-token-2',
            'device_id' => 'device-2',
        ])->assertCreated();

        $this->messagingMock->shouldReceive('unsubscribeFromTopic')
            ->once()
            ->with('announcements', ['active-token-1', 'active-token-2'])
            ->andReturn(['successCount' => 2]);

        $response = $this->postJson($this->unsubscribeUrl, [
            'topic' => 'announcements',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.topic', 'announcements')
            ->assertJsonPath('data.tokens_count', 2);
    }

    public function test_topic_service_sends_broadcast_message(): void
    {
        $messagingMock = Mockery::mock(Messaging::class);
        $messagingMock->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function (CloudMessage $message): bool {
                $array = $message->jsonSerialize();

                return isset($array['topic']) && $array['topic'] === 'all_students';
            }))
            ->andReturn(['name' => 'projects/test/messages/msg-123']);

        $service = new FcmTopicService($messagingMock);
        $result = $service->sendToTopic('all_students', 'Broadcast Title', 'Broadcast Body', ['extra' => 'data']);

        $this->assertSame(['name' => 'projects/test/messages/msg-123'], $result);
    }
}
