<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question's value for one optional dynamic filter.
 *
 * The filter is stored beside the value so one question can hold at most one
 * value per filter as a database rule.
 */
class FellowshipQuestionFilterValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'fellowship_question_id',
        'fellowship_filter_id',
        'fellowship_filter_value_id',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(FellowshipQuestion::class, 'fellowship_question_id');
    }

    public function filter(): BelongsTo
    {
        return $this->belongsTo(FellowshipFilter::class, 'fellowship_filter_id');
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(FellowshipFilterValue::class, 'fellowship_filter_value_id');
    }
}
