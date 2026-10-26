<?php

namespace App\Contracts;

use App\Data\AiChat\AgentChatRequest;
use App\Data\AiChat\AgentChatResponse;

interface AiChatAgentInterface
{
    public function generateResponse(AgentChatRequest $request): AgentChatResponse;
}
