<?php

namespace App\Models;

use App\Models\Concerns\HasSequentialOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends Model
{
    use HasFactory, HasSequentialOrder;

    protected $fillable = [
        'question_id',
        'option_text',
        'is_correct',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function orderParentColumn(): string
    {
        return 'question_id';
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
