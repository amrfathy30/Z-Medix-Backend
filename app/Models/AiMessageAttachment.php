<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessageAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['reference_type', 'reference_id', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'reference_id' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'ai_message_id');
    }
}
