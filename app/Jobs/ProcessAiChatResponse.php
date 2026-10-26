<?php

namespace App\Jobs;

use App\Contracts\AiChatAgentInterface;
use App\Data\AiChat\AgentChatRequest;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\AiChat\AiChatService;
use App\Services\AiChat\ChatBroadcaster;
use App\Services\AiChat\UnavailableAgentAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessAiChatResponse implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $messageId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('ai-chat-response:'.$this->messageId))->dontRelease()->expireAfter(75)];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(AiChatAgentInterface $agent, AiChatService $chat, ChatBroadcaster $broadcasts): void
    {
        $token = (string) Str::uuid();
        $message = DB::transaction(function () use ($token, $broadcasts, $chat): ?AiMessage {
            $message = AiMessage::query()->lockForUpdate()->find($this->messageId);
            if ($message === null || ! in_array($message->status, ['pending', 'processing'], true)) {
                return null;
            }
            if ($message->processing_attempts >= 3) {
                $chat->fail($message->id, 'AGENT_FAILED');

                return null;
            }
            $message->update(['status' => 'processing', 'processing_token' => $token, 'processing_attempts' => $message->processing_attempts + 1]);
            $broadcasts->changed($message, 'chat.response.processing');

            return $message;
        });
        if ($message === null) {
            return;
        }

        try {
            $question = $message->replyTo()->with('attachments')->first();
            if ($question === null) {
                return;
            }
            $response = $agent->generateResponse(new AgentChatRequest($message->conversation_id, $question->id, $question->content, $message->correlation_id, $question->attachments->map(fn ($attachment): array => [
                'reference_type' => $attachment->reference_type, 'reference_id' => $attachment->reference_id, 'snapshot' => $attachment->snapshot,
            ])->all()));
            if (trim($response->content) === '' || strlen($response->content) > config('ai_chat.max_response_bytes') || $response->correlationId !== $message->correlation_id) {
                throw new RuntimeException('Invalid agent response.');
            }
        } catch (Throwable) {
            if ($agent instanceof UnavailableAgentAdapter) {
                $chat->fail($message->id, 'AGENT_UNAVAILABLE', $token);

                return;
            }
            if ($message->processing_attempts >= 3) {
                $chat->fail($message->id, 'AGENT_FAILED', $token);

                return;
            }
            // Queue failure records must not contain an Agent's sensitive exception.
            throw new RuntimeException('AI chat processing failed.');
        }

        DB::transaction(function () use ($message, $token, $response, $broadcasts): void {
            // Submission and deletion lock the conversation before touching messages.
            $conversation = AiConversation::query()->lockForUpdate()->find($message->conversation_id);
            if ($conversation === null) {
                return;
            }
            $current = $conversation->messages()->lockForUpdate()->find($message->id);
            if ($current === null || $current->status !== 'processing' || $current->processing_token !== $token) {
                return;
            }
            $current->update(['content' => $response->content, 'status' => 'completed', 'error_code' => null, 'processing_token' => null]);
            $conversation->update(['last_message_at' => now()]);
            $broadcasts->changed($current, 'chat.response.completed');
        }, 3);
    }

    public function failed(?Throwable $exception): void
    {
        app(AiChatService::class)->fail($this->messageId, 'AGENT_FAILED');
    }
}
