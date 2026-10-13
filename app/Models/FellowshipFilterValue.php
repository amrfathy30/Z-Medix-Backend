<?php

namespace App\Models;

use App\Services\Fellowship\FellowshipFilterService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One allowed value of one Fellowship filter.
 *
 * Values are shared, not per-question: two questions on "Cardiology" point at
 * the same row, which is what makes Topic Performance add up.
 */
class FellowshipFilterValue extends Model
{
    use HasFactory;

    /**
     * The column's own limit, in characters.
     *
     * `value` is a `varchar(255)` on a `utf8mb4` table, so MySQL counts
     * characters and not bytes: 255 Arabic characters occupy 510 bytes and are
     * accepted, while 256 of anything are refused with error 1406. Anything
     * measuring this length must therefore use `mb_strlen()` — `strlen()` would
     * reject half of every Arabic value the platform exists to carry.
     *
     * Kept here, beside the column it describes, because two things have to
     * agree with it: the import's row validation, which rejects an over-long
     * cell while the file is being staged, and
     * {@see FellowshipFilterService::resolveValue()},
     * which is the one place a value row is created.
     */
    public const VALUE_MAX_LENGTH = 255;

    protected $fillable = [
        'fellowship_filter_id',
        'value',
    ];

    public function filter(): BelongsTo
    {
        return $this->belongsTo(FellowshipFilter::class, 'fellowship_filter_id');
    }

    /**
     * Questions carrying this value as their Performance Topic. Only meaningful
     * on values of the Fellowship's performance filter.
     */
    public function performanceQuestions(): HasMany
    {
        return $this->hasMany(FellowshipQuestion::class, 'performance_filter_value_id');
    }

    public function questionFilterValues(): HasMany
    {
        return $this->hasMany(FellowshipQuestionFilterValue::class, 'fellowship_filter_value_id');
    }

    public function fellowshipId(): ?int
    {
        return $this->filter?->fellowship_id;
    }
}
