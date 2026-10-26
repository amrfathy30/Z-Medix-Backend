<?php

namespace App\Services\AiChat;

use App\Contracts\AiChatAgentInterface;
use App\Data\AiChat\AgentChatRequest;
use App\Data\AiChat\AgentChatResponse;
use RuntimeException;

class MockAgentAdapter implements AiChatAgentInterface
{
    public function generateResponse(AgentChatRequest $request): AgentChatResponse
    {
        if (! app()->environment('local', 'testing') || config('ai_chat.mock_failure')) {
            throw new RuntimeException('Mock agent unavailable.');
        }

        return new AgentChatResponse('This is a mock chat response for integration testing only. No medical advice is provided.', $request->correlationId);
    }
}
