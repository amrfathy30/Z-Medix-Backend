<?php

namespace App\Services\AiChat;

use App\Events\AiChatMessageChanged;
use App\Http\Resources\Api\Student\AiMessageResource;
use App\Models\AiMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatBroadcaster
{
    public function changed(AiMessage $message, string $event): void
    {
        $payload = (new AiMessageResource($message))->resolve();
        $payload['message_id'] = $message->id;
        DB::afterCommit(function () use ($event, $payload): void {
            try {
                event(new AiChatMessageChanged($event, $payload));
            } catch (Throwable) {
                Log::warning('AI chat broadcast scheduling failed.', ['message_id' => $payload['message_id']]);
            }
        });
    }
}
