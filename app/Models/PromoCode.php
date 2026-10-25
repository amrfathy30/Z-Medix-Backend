<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PromoCode extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['code', 'discount_percentage', 'valid_from', 'valid_until', 'max_usage_limit', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'discount_percentage' => 'decimal:2',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'max_usage_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => self::normalize($value));
    }

    protected static function booted(): void
    {
        static::saving(function (self $promo): void {
            Validator::make($promo->getAttributes(), [
                'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('promo_codes')->ignore($promo->getKey())],
                'discount_percentage' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
                'max_usage_limit' => ['required', 'integer', 'min:1', 'max:4294967295'],
                'valid_from' => ['required', 'date'],
                'valid_until' => ['required', 'date', 'after_or_equal:valid_from'],
                'is_active' => ['boolean'],
            ])->validate();

            if ($promo->exists && $promo->isDirty('code')) {
                throw ValidationException::withMessages(['code' => __('promo_codes.code_fixed')]);
            }

            if ($promo->exists && $promo->max_usage_limit < $promo->usages()->whereIn('status', ['reserved', 'consumed'])->count()) {
                throw ValidationException::withMessages(['max_usage_limit' => __('promo_codes.limit_too_low')]);
            }
        });
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromoCodeUsage::class);
    }

    public function scopeWithUsageCounts(Builder $query): Builder
    {
        return $query->withCount([
            'usages as consumed_count' => fn (Builder $query): Builder => $query->where('status', 'consumed'),
            'usages as reserved_count' => fn (Builder $query): Builder => $query->where('status', 'reserved'),
        ]);
    }

    protected function availableCount(): Attribute
    {
        return Attribute::make(get: fn (): int => max(0, $this->max_usage_limit - $this->consumed_count - $this->reserved_count));
    }
}
