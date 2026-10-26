<?php

namespace App\Data\AiChat;

final readonly class AgentChatResponse
{
    public function __construct(public string $content, public string $correlationId) {}
}
