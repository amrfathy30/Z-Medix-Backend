<?php

namespace Database\Factories;

use App\Models\AiConversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AiMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['conversation_id' => AiConversation::factory(), 'role' => 'user', 'content' => 'Test question', 'status' => 'completed', 'client_message_id' => (string) Str::uuid(), 'correlation_id' => (string) Str::uuid()];
    }
}
