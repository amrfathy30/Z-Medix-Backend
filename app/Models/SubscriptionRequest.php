<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A student asking for a plan. Payment is settled outside the app, so this is
 * only an inbox item: the admin approves it by recording a period, or rejects
 * it.
 */
class SubscriptionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_id',
        'billing_cycle',
        'status',
        'admin_note',
        'handled_by',
        'handled_at',
        'pricing_snapshot',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'status' => SubscriptionRequestStatus::class,
            'handled_at' => 'datetime',
            'pricing_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        // A deleted student's subscription and invoices stay visible to the admin.
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Soft-deleted plans still resolve: an old request keeps the plan it named.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    public function promoCodeUsages(): HasMany
    {
        return $this->hasMany(PromoCodeUsage::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'handled_by');
    }

    /**
     * The period recorded when this request was approved.
     */
    public function period(): HasOne
    {
        return $this->hasOne(SubscriptionPeriod::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SubscriptionRequestStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === SubscriptionRequestStatus::Pending;
    }
}
