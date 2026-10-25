<?php

namespace Database\Factories;

use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\SubscriptionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromoCodeUsage> */
class PromoCodeUsageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'promo_code_id' => PromoCode::factory(),
            'subscription_request_id' => SubscriptionRequest::factory(),
            'user_id' => fn (array $attributes): int => SubscriptionRequest::findOrFail($attributes['subscription_request_id'])->user_id,
            'status' => 'reserved',
            'code' => fn (array $attributes): string => PromoCode::findOrFail($attributes['promo_code_id'])->code,
            'plan_name' => 'Basic',
            'billing_cycle' => 'monthly',
            'original_price' => '100.00',
            'discount_percentage' => '20.00',
            'discount_amount' => '20.00',
            'final_price' => '80.00',
            'currency' => 'USD',
        ];
    }
}
