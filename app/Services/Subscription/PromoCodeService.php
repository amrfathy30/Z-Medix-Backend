<?php

namespace App\Services\Subscription;

use App\Enums\BillingCycle;
use App\Exceptions\Subscription\SubscriptionException;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\User;

class PromoCodeService
{
    /** @return array{valid: bool, code: string, discount_percentage: float, original_price: float, discount_amount: float, final_price: float, currency: string} */
    public function validate(User $student, Plan $plan, BillingCycle $cycle, string $code, bool $lock = false): array
    {
        $this->validatePlan($plan, $cycle);
        $query = PromoCode::query()->where('code', PromoCode::normalize($code));
        $promo = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($promo === null) {
            throw SubscriptionException::promo('PROMO_CODE_INVALID');
        }
        if (! $promo->is_active) {
            throw SubscriptionException::promo('PROMO_CODE_INACTIVE');
        }
        $now = now();
        if ($now->lt($promo->valid_from)) {
            throw SubscriptionException::promo('PROMO_CODE_NOT_STARTED');
        }
        if ($now->gt($promo->valid_until)) {
            throw SubscriptionException::promo('PROMO_CODE_EXPIRED');
        }
        if ($promo->usages()->where('user_id', $student->getKey())->whereIn('status', ['reserved', 'consumed'])->exists()) {
            throw SubscriptionException::promo('PROMO_CODE_ALREADY_USED');
        }
        if ($promo->usages()->whereIn('status', ['reserved', 'consumed'])->count() >= $promo->max_usage_limit) {
            throw SubscriptionException::promo('PROMO_CODE_EXHAUSTED');
        }

        return ['valid' => true, 'code' => $promo->code] + $this->pricing($plan, $cycle, $promo->discount_percentage);
    }

    public function validatePlan(Plan $plan, BillingCycle $cycle): void
    {
        if (! $plan->is_active || $plan->trashed()) {
            throw SubscriptionException::planUnavailable();
        }
        if (! $plan->offers($cycle)) {
            throw SubscriptionException::cycleNotOffered();
        }
    }

    /** @return array{discount_percentage: float, original_price: float, discount_amount: float, final_price: float, currency: string} */
    public function pricing(Plan $plan, BillingCycle $cycle, string $percentage = '0.00'): array
    {
        $cents = (int) str_replace('.', '', $plan->priceFor($cycle));
        $basisPoints = (int) str_replace('.', '', number_format((float) $percentage, 2, '.', ''));
        $discount = intdiv($cents * $basisPoints + 5000, 10000);

        return [
            'discount_percentage' => $basisPoints / 100,
            'original_price' => $cents / 100,
            'discount_amount' => $discount / 100,
            'final_price' => ($cents - $discount) / 100,
            'currency' => $plan->currency,
        ];
    }
}
