<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's subscription: the container their paid periods hang off. There is
 * one per student; renewals add periods rather than rows.
 *
 * Nothing about its state is stored. It is active when it is not cancelled and a
 * period covers now, so access ends exactly at the end of the paid period with
 * no scheduler involved.
 */
class StudentSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        // A deleted student's subscription and invoices stay visible to the admin.
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Oldest first, which is the order an invoice history reads in.
     */
    public function periods(): HasMany
    {
        return $this->hasMany(SubscriptionPeriod::class)
            ->orderBy('starts_at')
            ->orderBy('id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * The period granting access at the given moment, or null. Ignores
     * cancellation: callers that care use {@see status()} / {@see isActive()}.
     * The one that started last wins permanently, including after it expires.
     */
    public function currentPeriod(?CarbonInterface $at = null): ?SubscriptionPeriod
    {
        return $this->periods()
            ->getQuery()
            ->reorder()
            ->effectiveAt($at)
            ->covering($at)
            ->with('plan.featureLimits')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    public function status(?CarbonInterface $at = null): SubscriptionStatus
    {
        if ($this->isCancelled()) {
            return SubscriptionStatus::Cancelled;
        }

        if ($this->currentPeriod($at) !== null) {
            return SubscriptionStatus::Active;
        }

        return $this->periods()->exists() ? SubscriptionStatus::Expired : SubscriptionStatus::None;
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        return $this->status($at) === SubscriptionStatus::Active;
    }

    /**
     * The end of the effective period, including after it expires.
     */
    public function latestEndsAt(): ?CarbonInterface
    {
        return $this->periods()
            ->getQuery()
            ->reorder()
            ->effectiveAt()
            ->orderByDesc('ends_at')
            ->first()
            ?->ends_at;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')
            ->whereHas('periods', fn (Builder $periods) => $periods->effectiveAt()->covering());
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->whereNotNull('cancelled_at');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')
            ->whereHas('periods')
            ->whereDoesntHave('periods', fn (Builder $periods) => $periods->effectiveAt()->covering());
    }
}
