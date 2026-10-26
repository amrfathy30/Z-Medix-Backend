<?php

namespace App\Data\AiChat;

final readonly class AgentChatRequest
{
    public function __construct(
        public int $conversationId,
        public int $userMessageId,
        public string $content,
        public string $correlationId,
    ) {}
}
