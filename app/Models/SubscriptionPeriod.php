<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One paid period of a subscription: the invoice the admin records after taking
 * payment on WhatsApp. It snapshots the plan, cycle and amount that were agreed,
 * so later plan or price edits never rewrite history.
 *
 * The payment screenshot lives on the private disk, so unlike most media here it
 * has no public URL: it is only handed over by the admin-authenticated proof
 * route.
 */
class SubscriptionPeriod extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    public const PROOF_COLLECTION = 'payment_proof';

    /** `local` is rooted outside the public storage link. */
    public const PROOF_DISK = 'local';

    protected $fillable = [
        'student_subscription_id',
        'plan_id',
        'subscription_request_id',
        'billing_cycle',
        'starts_at',
        'ends_at',
        'amount',
        'currency',
        'paid_at',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'paid_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PROOF_COLLECTION)
            ->useDisk(self::PROOF_DISK)
            ->acceptsMimeTypes(config('media.payment_proof_mime_types', []))
            ->singleFile();
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(StudentSubscription::class, 'student_subscription_id');
    }

    /**
     * Soft-deleted plans still resolve: a period keeps the plan it was paid for.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SubscriptionRequest::class, 'subscription_request_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recorded_by');
    }

    /**
     * Periods that cover the given moment (now by default). The end is
     * exclusive, so a period stops granting access at exactly `ends_at`.
     */
    public function scopeCovering(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('starts_at', '<=', $at)->where('ends_at', '>', $at);
    }

    /**
     * The latest started period stays authoritative after it ends. A previous
     * overlapping invoice must never resume granting access after a plan change.
     */
    public function scopeEffectiveAt(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();
        $table = $this->getTable();

        return $query->where($table.'.starts_at', '<=', $at)
            ->whereNotExists(function (QueryBuilder $later) use ($at, $table): void {
                $later->select('later.id')->from($table.' as later')
                    ->whereColumn('later.student_subscription_id', $table.'.student_subscription_id')
                    ->where('later.starts_at', '<=', $at)
                    ->where(function (QueryBuilder $order) use ($table): void {
                        $order->whereColumn('later.starts_at', '>', $table.'.starts_at')
                            ->orWhere(function (QueryBuilder $tie) use ($table): void {
                                $tie->whereColumn('later.starts_at', $table.'.starts_at')
                                    ->whereColumn('later.id', '>', $table.'.id');
                            });
                    });
            });
    }

    public function proof(): ?Media
    {
        return $this->getFirstMedia(self::PROOF_COLLECTION);
    }
}
