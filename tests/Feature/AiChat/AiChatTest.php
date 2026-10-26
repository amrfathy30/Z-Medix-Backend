<?php

namespace Tests\Feature\AiChat;

use App\Contracts\AiChatAgentInterface;
use App\Data\AiChat\AgentChatRequest;
use App\Data\AiChat\AgentChatResponse;
use App\Events\AiChatMessageChanged;
use App\Jobs\ProcessAiChatResponse;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Plan;
use App\Models\User;
use App\Services\AiChat\AiChatService;
use App\Services\AiChat\ChatBroadcaster;
use App\Services\AiChat\MockAgentAdapter;
use App\Services\AiChat\UnavailableAgentAdapter;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\SubscribesStudents;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use DatabaseMigrations, SubscribesStudents;

    private User $student;

    private QueueManager $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_chat.queue_connection' => 'database', 'ai_chat.adapter' => 'mock']);
        $this->student = $this->subscribedStudent();
        Sanctum::actingAs($this->student);
        $this->realQueue = Queue::getFacadeRoot();
        Queue::fake();
    }

    private function conversation(): AiConversation
    {
        return AiConversation::factory()->for($this->student)->create();
    }

    /** @return array<string, mixed> */
    private function submit(?AiConversation $conversation = null, string $key = 'test-1'): array
    {
        return $this->postJson('/api/student/ai/conversations/'.($conversation ?? $this->conversation())->id.'/messages', [
            'content' => 'Test question', 'client_message_id' => $key,
        ])->assertStatus(202)->json('data');
    }

    private function process(int $id, ?AiChatAgentInterface $agent = null): void
    {
        (new ProcessAiChatResponse($id))->handle($agent ?? new MockAgentAdapter, app(AiChatService::class), app(ChatBroadcaster::class));
    }

    public function test_conversation_crud_and_activity_order_and_pagination(): void
    {
        $foreign = AiConversation::factory()->create();
        $older = AiConversation::factory()->for($this->student)->create(['last_message_at' => now()->subDay()]);
        $id = $this->postJson('/api/student/ai/conversations')->assertCreated()->assertJsonPath('data.title', 'New conversation')->json('data.id');
        $this->getJson('/api/student/ai/conversations?per_page=1')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/student/ai/conversations/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->patchJson('/api/student/ai/conversations/'.$id, ['title' => 'Updated title'])->assertOk()->assertJsonPath('data.title', 'Updated title');
        $this->deleteJson('/api/student/ai/conversations/'.$id)->assertOk();
        $this->getJson('/api/student/ai/conversations/'.$id)->assertNotFound();
        $this->assertModelExists($foreign);
        $this->assertModelExists($older);
    }

    public function test_other_students_conversations_and_messages_are_not_found(): void
    {
        $foreign = AiConversation::factory()->create();
        $message = AiMessage::factory()->create(['conversation_id' => $foreign->id]);
        foreach (['get', 'patch', 'delete'] as $method) {
            $this->{$method.'Json'}('/api/student/ai/conversations/'.$foreign->id, ['title' => 'Attempt'])->assertNotFound();
        }
        $this->getJson('/api/student/ai/conversations/'.$foreign->id.'/messages')->assertNotFound();
        $this->postJson('/api/student/ai/conversations/'.$foreign->id.'/messages', ['content' => 'Question', 'client_message_id' => 'foreign'])->assertNotFound();
        $this->getJson('/api/student/ai/messages/'.$message->id)->assertNotFound();
    }

    public function test_authentication_and_existing_plan_gate_are_required(): void
    {
        auth()->forgetGuards();
        $this->getJson('/api/student/ai/conversations')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/student/ai/conversations')->assertOk()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
        Sanctum::actingAs($this->subscribedStudent(Plan::factory()->basic()->create()));
        $this->getJson('/api/student/ai/conversations')->assertOk()->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');
    }

    public function test_private_channel_authorization_only_allows_owner(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        require base_path('routes/channels.php');
        $mine = $this->conversation();
        $foreign = AiConversation::factory()->create();
        $endpoint = '/api/student/broadcasting/auth';
        $this->postJson($endpoint, ['socket_id' => '123.456', 'channel_name' => 'private-student.ai.conversation.'.$mine->id])->assertOk()->assertJsonStructure(['auth']);
        $this->postJson($endpoint, ['socket_id' => '123.456', 'channel_name' => 'private-student.ai.conversation.'.$foreign->id])->assertForbidden();
        $this->postJson($endpoint, ['socket_id' => '123.456', 'channel_name' => 'private-student.ai.conversation.999999'])->assertForbidden();
        $this->deleteJson('/api/student/ai/conversations/'.$mine->id)->assertOk();
        $this->postJson($endpoint, ['socket_id' => '123.456', 'channel_name' => 'private-student.ai.conversation.'.$mine->id])->assertForbidden();
        auth()->forgetGuards();
        $this->postJson($endpoint, ['socket_id' => '123.456', 'channel_name' => 'private-student.ai.conversation.1'])->assertUnauthorized();
    }

    public function test_submission_persists_both_records_and_schedules_only_identifiers(): void
    {
        $data = $this->submit();
        $this->assertSame('completed', $data['user_message']['status']);
        $this->assertSame('pending', $data['assistant_message']['status']);
        $this->assertNull($data['assistant_message']['content']);
        $this->assertSame($data['user_message']['id'], $data['assistant_message']['reply_to_message_id']);
        $this->assertDatabaseCount('ai_messages', 2);
        Queue::assertPushed(ProcessAiChatResponse::class, fn ($job) => $job->messageId === $data['assistant_message']['id'] && $job->connection === 'database');
    }

    public function test_client_retry_reuses_records_and_rejects_changed_content(): void
    {
        $conversation = $this->conversation();
        $first = $this->submit($conversation);
        $this->postJson('/api/student/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Test question', 'client_message_id' => 'test-1'])
            ->assertOk()->assertJsonPath('data.assistant_message.id', $first['assistant_message']['id']);
        $this->postJson('/api/student/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Changed', 'client_message_id' => 'test-1'])->assertUnprocessable();
        Queue::assertPushed(ProcessAiChatResponse::class, 1);
        $this->assertDatabaseCount('ai_messages', 2);
    }

    public function test_message_validation_and_title_limits(): void
    {
        $path = '/api/student/ai/conversations/'.$this->conversation()->id.'/messages';
        foreach ([[], ['content' => ' ', 'client_message_id' => 'id'], ['content' => str_repeat('x', 10001), 'client_message_id' => 'id'], ['content' => 'Question', 'client_message_id' => 'bad key']] as $payload) {
            $this->postJson($path, $payload)->assertUnprocessable();
        }
        $this->postJson('/api/student/ai/conversations', ['title' => str_repeat('x', 151)])->assertUnprocessable();
        $this->assertDatabaseCount('ai_messages', 0);
    }

    public function test_success_persists_before_broadcast_and_job_retry_is_idempotent(): void
    {
        $id = $this->submit()['assistant_message']['id'];
        $events = [];
        Event::listen(AiChatMessageChanged::class, function (AiChatMessageChanged $event) use (&$events): void {
            $events[] = $event->broadcastAs();
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($event->payload['status'], AiMessage::findOrFail($event->payload['message_id'])->status);
            $this->assertSame('private-student.ai.conversation.'.$event->payload['conversation_id'], $event->broadcastOn()[0]->name);
            if ($event->broadcastAs() === 'chat.response.completed') {
                $this->assertSame($event->payload['content'], AiMessage::findOrFail($event->payload['message_id'])->content);
            }
        });
        $this->process($id);
        $this->process($id);
        $this->assertSame(['chat.response.processing', 'chat.response.completed'], $events);
        $this->assertDatabaseCount('ai_messages', 2);
        $this->getJson('/api/student/ai/messages/'.$id)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.error_code', null);
    }

    public function test_reconnection_retrieves_complete_reply_and_older_pages(): void
    {
        $conversation = $this->conversation();
        $data = $this->submit($conversation);
        $this->process($data['assistant_message']['id']);
        $this->getJson('/api/student/ai/conversations/'.$conversation->id.'/messages?per_page=1')->assertOk()->assertJsonPath('data.0.id', $data['assistant_message']['id'])->assertJsonPath('data.0.status', 'completed')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/student/ai/conversations/'.$conversation->id.'/messages?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $data['user_message']['id']);
    }

    public function test_agent_failure_retries_then_persists_safe_terminal_error(): void
    {
        config(['ai_chat.mock_failure' => true]);
        $id = $this->submit()['assistant_message']['id'];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $this->process($id);
                $this->assertSame(3, $attempt);
            } catch (RuntimeException $exception) {
                $this->assertSame('AI chat processing failed.', $exception->getMessage());
            }
        }
        $this->getJson('/api/student/ai/messages/'.$id)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'AGENT_FAILED')->assertJsonPath('data.content', null);
    }

    public function test_unconfigured_agent_fails_and_mock_is_refused_in_production(): void
    {
        $id = $this->submit()['assistant_message']['id'];
        $this->process($id, new UnavailableAgentAdapter);
        $this->assertSame('AGENT_UNAVAILABLE', AiMessage::findOrFail($id)->error_code);
        $this->app->instance('env', 'production');
        $this->assertInstanceOf(UnavailableAgentAdapter::class, app(AiChatAgentInterface::class));
        try {
            (new MockAgentAdapter)->generateResponse(new AgentChatRequest(1, 1, 'Test', 'correlation'));
            $this->fail('Production must refuse the mock.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_queue_outage_returns_503_with_persisted_failed_reply(): void
    {
        $dispatcher = $this->mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Sensitive connection error'));
        $conversation = $this->conversation();
        $data = $this->postJson('/api/student/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Test', 'client_message_id' => 'outage'])
            ->assertStatus(503)->assertJsonPath('code', 'SCHEDULING_FAILED')->assertJsonPath('data.assistant_message.status', 'failed')->json('data');
        $this->assertDatabaseCount('ai_messages', 2);
        $this->getJson('/api/student/ai/messages/'.$data['assistant_message']['id'])->assertOk()->assertJsonPath('data.error_code', 'SCHEDULING_FAILED');
    }

    public function test_sync_queue_is_rejected(): void
    {
        config(['ai_chat.queue_connection' => 'sync']);
        $this->postJson('/api/student/ai/conversations/'.$this->conversation()->id.'/messages', ['content' => 'Test', 'client_message_id' => 'sync'])
            ->assertStatus(503)->assertJsonPath('data.assistant_message.status', 'failed');
        Queue::assertNotPushed(ProcessAiChatResponse::class);
    }

    public function test_deletion_before_job_and_during_agent_execution_cannot_recreate_records(): void
    {
        $conversation = $this->conversation();
        $id = $this->submit($conversation)['assistant_message']['id'];
        $this->deleteJson('/api/student/ai/conversations/'.$conversation->id)->assertOk();
        $this->process($id);
        $this->assertDatabaseCount('ai_messages', 0);
        $conversation = $this->conversation();
        $id = $this->submit($conversation, 'second')['assistant_message']['id'];
        $agent = $this->mock(AiChatAgentInterface::class);
        $agent->shouldReceive('generateResponse')->once()->andReturnUsing(function (AgentChatRequest $request) use ($conversation): AgentChatResponse {
            $this->deleteJson('/api/student/ai/conversations/'.$conversation->id)->assertOk();

            return new AgentChatResponse('Late response', $request->correlationId);
        });
        $this->process($id, $agent);
        $this->assertDatabaseCount('ai_conversations', 0);
        $this->assertDatabaseCount('ai_messages', 0);
    }

    public function test_broadcast_outage_does_not_lose_completed_response(): void
    {
        $id = $this->submit()['assistant_message']['id'];
        Event::listen(AiChatMessageChanged::class, fn () => throw new RuntimeException('Broadcast outage'));
        $this->process($id);
        $this->getJson('/api/student/ai/messages/'.$id)->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_recovery_schedules_stranded_work_and_terminal_records_are_ignored(): void
    {
        $id = $this->submit()['assistant_message']['id'];
        AiMessage::whereKey($id)->update(['status' => 'processing', 'updated_at' => now()->subMinutes(6)]);
        Queue::fake();
        $this->artisan('ai-chat:recover')->assertSuccessful();
        Queue::assertPushed(ProcessAiChatResponse::class, 1);
        $this->process($id);
        Queue::fake();
        $this->artisan('ai-chat:recover')->assertSuccessful();
        Queue::assertNotPushed(ProcessAiChatResponse::class);
    }

    public function test_failed_job_callback_persists_terminal_state_but_does_not_overwrite_success(): void
    {
        $id = $this->submit()['assistant_message']['id'];
        (new ProcessAiChatResponse($id))->failed(new RuntimeException('Private details'));
        $this->assertSame('failed', AiMessage::findOrFail($id)->status);
        $id = $this->submit(null, 'second')['assistant_message']['id'];
        $this->process($id);
        (new ProcessAiChatResponse($id))->failed(new RuntimeException('Private details'));
        $this->assertSame('completed', AiMessage::findOrFail($id)->status);
    }

    public function test_broadcasts_are_discarded_on_rollback_and_delayed_until_commit(): void
    {
        $data = $this->submit();
        Event::fake([AiChatMessageChanged::class]);
        $message = AiMessage::findOrFail($data['user_message']['id']);
        DB::beginTransaction();
        app(ChatBroadcaster::class)->changed($message, 'chat.message.created');
        Event::assertNotDispatched(AiChatMessageChanged::class);
        DB::rollBack();
        Event::assertNotDispatched(AiChatMessageChanged::class);
        DB::beginTransaction();
        app(ChatBroadcaster::class)->changed($message, 'chat.message.created');
        Event::assertNotDispatched(AiChatMessageChanged::class);
        DB::commit();
        Event::assertDispatched(AiChatMessageChanged::class);
    }

    public function test_actual_database_queue_processes_the_response_asynchronously(): void
    {
        Queue::swap($this->realQueue);
        Event::fake([AiChatMessageChanged::class]);
        $id = $this->submit()['assistant_message']['id'];
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('pending', AiMessage::findOrFail($id)->status);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('completed', AiMessage::findOrFail($id)->status);
    }

    public function test_invalid_agent_output_cannot_be_persisted_as_a_completed_answer(): void
    {
        foreach (['', str_repeat('x', 20001)] as $index => $content) {
            $id = $this->submit(null, 'invalid-'.$index)['assistant_message']['id'];
            $agent = $this->mock(AiChatAgentInterface::class);
            $agent->shouldReceive('generateResponse')->andReturnUsing(fn (AgentChatRequest $request) => new AgentChatResponse($content, $request->correlationId));
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $this->process($id, $agent);
                } catch (RuntimeException) {
                    $this->assertLessThan(2, $attempt);
                }
            }
            $this->assertSame('failed', AiMessage::findOrFail($id)->status);
            $this->assertNull(AiMessage::findOrFail($id)->content);
        }
    }

    public function test_submission_throttle_prevents_additional_work(): void
    {
        $conversation = $this->conversation();
        for ($index = 0; $index < 20; $index++) {
            $this->submit($conversation, 'throttle-'.$index);
        }
        $this->postJson('/api/student/ai/conversations/'.$conversation->id.'/messages', ['content' => 'Question', 'client_message_id' => 'over-limit'])->assertStatus(429);
        $this->assertDatabaseCount('ai_messages', 40);
    }
}
