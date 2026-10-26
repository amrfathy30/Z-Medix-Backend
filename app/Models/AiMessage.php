<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiMessage extends Model
{
    use HasFactory;

    protected $fillable = ['conversation_id', 'reply_to_message_id', 'role', 'content', 'status', 'client_message_id', 'correlation_id', 'processing_token', 'error_code', 'processing_attempts'];

    protected $attributes = ['status' => 'pending', 'processing_attempts' => 0];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function response(): HasOne
    {
        return $this->hasOne(self::class, 'reply_to_message_id');
    }
}
