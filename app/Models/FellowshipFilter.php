<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dynamic classification of one Fellowship.
 *
 * The filter's `name` is its identity and does not come from the spreadsheet
 * header on every import — the header may be spelled differently next time and
 * is mapped onto this row instead, so renaming a column never forks a filter.
 */
class FellowshipFilter extends Model
{
    use HasFactory;

    protected $fillable = [
        'fellowship_id',
        'name',
        'is_performance',
    ];

    protected function casts(): array
    {
        return [
            'is_performance' => 'boolean',
        ];
    }

    public function fellowship(): BelongsTo
    {
        return $this->belongsTo(Fellowship::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(FellowshipFilterValue::class)->orderBy('value');
    }

    public function questionFilterValues(): HasMany
    {
        return $this->hasMany(FellowshipQuestionFilterValue::class);
    }

    /**
     * @param  Builder<FellowshipFilter>  $query
     * @return Builder<FellowshipFilter>
     */
    public function scopeDynamic(Builder $query): Builder
    {
        return $query->where('is_performance', false);
    }
}
