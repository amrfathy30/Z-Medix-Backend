<?php

namespace App\Services\AiChat;

use App\Jobs\ProcessAiChatResponse;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AiChatService
{
    public function __construct(private ChatBroadcaster $broadcasts, private Dispatcher $bus, private AttachmentResolver $attachments) {}

    public function ownedConversation(User $student, string $id): AiConversation
    {
        return AiConversation::query()->where('user_id', $student->id)->findOrFail($id);
    }

    /**
     * @param  array<int, array{type: string, id: int}>  $attachments
     * @return array{user_message: AiMessage, assistant_message: AiMessage, created: bool, scheduled: bool}
     */
    public function submit(User $student, string $conversationId, string $content, string $clientMessageId, array $attachments = []): array
    {
        $result = DB::transaction(function () use ($student, $conversationId, $content, $clientMessageId, $attachments): array {
            $conversation = AiConversation::query()->where('user_id', $student->id)->lockForUpdate()->findOrFail($conversationId);
            $existing = $conversation->messages()->with('attachments')->where('client_message_id', $clientMessageId)->first();
            if ($existing !== null) {
                if ($existing->content !== $content || $this->referenceKeys($attachments) !== $this->referenceKeys($existing->attachments->map(fn ($attachment): array => ['type' => $attachment->reference_type, 'id' => $attachment->reference_id])->all())) {
                    throw ValidationException::withMessages(['client_message_id' => 'This identifier was already used for different content or attachments.']);
                }

                return ['user_message' => $existing, 'assistant_message' => $existing->response()->with('attachments')->firstOrFail(), 'created' => false];
            }

            $resolved = $this->attachments->resolve($student, $attachments);
            $correlationId = (string) Str::uuid();
            $message = $conversation->messages()->create([
                'role' => 'user', 'status' => 'completed', 'content' => $content,
                'client_message_id' => $clientMessageId, 'correlation_id' => $correlationId,
            ]);
            $message->attachments()->createMany($resolved);
            $message->load('attachments');
            $reply = $conversation->messages()->create([
                'role' => 'assistant', 'status' => 'pending', 'reply_to_message_id' => $message->id,
                'correlation_id' => $correlationId,
            ]);
            $conversation->update(['last_message_at' => now()]);
            $this->broadcasts->changed($message, 'chat.message.created');
            $this->broadcasts->changed($reply, 'chat.message.created');

            return ['user_message' => $message, 'assistant_message' => $reply, 'created' => true];
        }, 3);

        $scheduled = true;
        if ($result['created']) {
            try {
                $this->schedule($result['assistant_message']->id);
            } catch (Throwable) {
                $this->fail($result['assistant_message']->id, 'SCHEDULING_FAILED');
                $scheduled = false;
            }
        }

        return [...$result, 'assistant_message' => $result['assistant_message']->refresh(), 'scheduled' => $scheduled];
    }

    /**
     * @param  array<int, array{type: string, id: int}>  $references
     * @return array<int, string>
     */
    private function referenceKeys(array $references): array
    {
        $keys = array_map(fn (array $reference): string => $reference['type'].':'.(int) $reference['id'], $references);
        sort($keys);

        return $keys;
    }

    public function schedule(int $messageId): void
    {
        $connection = config('ai_chat.queue_connection');
        if (! in_array(config("queue.connections.{$connection}.driver"), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new RuntimeException('AI chat requires a durable asynchronous queue.');
        }

        $this->bus->dispatch((new ProcessAiChatResponse($messageId))->onConnection($connection)->onQueue(config('ai_chat.queue'))->afterCommit());
    }

    public function fail(int $messageId, string $code, ?string $token = null): void
    {
        DB::transaction(function () use ($messageId, $code, $token): void {
            $message = AiMessage::query()->lockForUpdate()->find($messageId);
            if ($message === null || ! in_array($message->status, ['pending', 'processing'], true)
                || ($token !== null && $message->processing_token !== $token)) {
                return;
            }
            $message->update(['status' => 'failed', 'error_code' => $code, 'processing_token' => null]);
            $this->broadcasts->changed($message, 'chat.response.failed');
            Log::warning('AI chat response failed.', ['message_id' => $messageId, 'correlation_id' => $message->correlation_id, 'error_code' => $code]);
        });
    }
}
