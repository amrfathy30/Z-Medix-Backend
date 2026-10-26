<?php

namespace App\Console\Commands;

use App\Models\AiMessage;
use App\Services\AiChat\AiChatService;
use Illuminate\Console\Command;
use Throwable;

class RecoverAiChatResponses extends Command
{
    protected $signature = 'ai-chat:recover';

    protected $description = 'Schedule pending or stranded AI chat responses';

    public function handle(AiChatService $chat): int
    {
        $failed = false;
        AiMessage::query()->where('role', 'assistant')->whereIn('status', ['pending', 'processing'])
            ->where('updated_at', '<', now()->subMinutes(5))
            ->chunkById(100, function ($messages) use ($chat, &$failed): void {
                foreach ($messages as $message) {
                    try {
                        $chat->schedule($message->id);
                    } catch (Throwable) {
                        $chat->fail($message->id, 'SCHEDULING_FAILED');
                        $failed = true;
                    }
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
