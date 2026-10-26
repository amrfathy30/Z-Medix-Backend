<?php

namespace App\Http\Resources\Api\Student;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'reply_to_message_id' => $this->reply_to_message_id,
            'role' => $this->role,
            'content' => $this->content,
            'status' => $this->status,
            'client_message_id' => $this->client_message_id,
            'correlation_id' => $this->correlation_id,
            'error_code' => $this->error_code,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
