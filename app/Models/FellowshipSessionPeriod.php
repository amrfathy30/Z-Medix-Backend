<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stretch of time the student was working on a session.
 *
 * Study Time is the sum of these, so a session paused overnight does not bill
 * the night. `open_marker` holds 1 while open and NULL once closed, which is
 * what the table's unique index uses to admit only one open period per session.
 */
class FellowshipSessionPeriod extends Model
{
    use HasFactory;

    /**
     * The value `open_marker` carries while the period is open. Closing a
     * period sets it to null, which the unique index treats as distinct.
     */
    public const OPEN = 1;

    protected $fillable = [
        'fellowship_quiz_session_id',
        'started_at',
        'ended_at',
        'duration_seconds',
        'open_marker',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'open_marker' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(FellowshipQuizSession::class, 'fellowship_quiz_session_id');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * How long this period ran. An open period counts up to the given instant,
     * so Study Time includes the stretch the student is in right now.
     */
    public function durationSeconds(?Carbon $asOf = null): int
    {
        if ($this->ended_at !== null) {
            return (int) ($this->duration_seconds ?? $this->started_at->diffInSeconds($this->ended_at, absolute: true));
        }

        $asOf ??= now();

        return max(0, (int) $this->started_at->diffInSeconds($asOf, absolute: true));
    }

    /**
     * @param  Builder<FellowshipSessionPeriod>  $query
     * @return Builder<FellowshipSessionPeriod>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
