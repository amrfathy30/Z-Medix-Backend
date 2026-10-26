<?php

namespace App\Services\AiChat;

use App\Contracts\AiChatAgentInterface;
use App\Data\AiChat\AgentChatRequest;
use App\Data\AiChat\AgentChatResponse;
use RuntimeException;

class UnavailableAgentAdapter implements AiChatAgentInterface
{
    public function generateResponse(AgentChatRequest $request): AgentChatResponse
    {
        throw new RuntimeException('Agent unavailable.');
    }
}
