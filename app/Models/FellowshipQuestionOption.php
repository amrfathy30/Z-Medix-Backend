<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer option of a Fellowship question. Exactly one option per question
 * is correct; "at least two options, exactly one correct" is enforced where a
 * violation can be attributed to a spreadsheet row or a form field.
 */
class FellowshipQuestionOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'fellowship_question_id',
        'option_text',
        'explanation',
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

    public function question(): BelongsTo
    {
        return $this->belongsTo(FellowshipQuestion::class, 'fellowship_question_id');
    }
}
