<?php

namespace App\Models;

use App\Enums\PromoCodeUsageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PromoCodeUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'promo_code_id', 'user_id', 'subscription_request_id', 'status', 'code', 'plan_name',
        'billing_cycle', 'original_price', 'discount_percentage', 'discount_amount', 'final_price', 'currency',
    ];

    protected $attributes = ['status' => 'reserved'];

    protected function casts(): array
    {
        return [
            'status' => PromoCodeUsageStatus::class,
            'original_price' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'final_price' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $usage): void {
            if (array_diff(array_keys($usage->getDirty()), ['status', 'updated_at']) !== []) {
                throw new LogicException('Promo usage snapshots are immutable.');
            }
        });
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function subscriptionRequest(): BelongsTo
    {
        return $this->belongsTo(SubscriptionRequest::class);
    }
}
