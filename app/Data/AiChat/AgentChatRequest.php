<?php

namespace App\Data\AiChat;

final readonly class AgentChatRequest
{
    /** @param array<int, array{reference_type: string, reference_id: int, snapshot: array<string, mixed>}> $attachments */
    public function __construct(
        public int $conversationId,
        public int $userMessageId,
        public string $content,
        public string $correlationId,
        public array $attachments = [],
    ) {}
}
