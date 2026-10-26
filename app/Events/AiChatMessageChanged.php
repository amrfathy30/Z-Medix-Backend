<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class AiChatMessageChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public string $connection;

    public string $queue;

    public int $tries = 3;

    /** @param array<string, mixed> $payload */
    public function __construct(public string $eventName, public array $payload)
    {
        $this->connection = config('ai_chat.queue_connection');
        $this->queue = config('ai_chat.queue');
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('student.ai.conversation.'.$this->payload['conversation_id'])];
    }

    /** @return array<int, string> */
    public function broadcastConnections(): array
    {
        return ['reverb'];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
