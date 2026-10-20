<?php

namespace App\Http\Requests\Api\Student;

use App\Enums\BillingCycle;
use App\Models\Plan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A student asking for a plan on a billing cycle.
 *
 * `exists` establishes only that the plan is real. Whether it can be bought
 * right now (active, offered for that cycle) is the subscription service's
 * decision, which answers with a coded error the app can act on.
 */
class StoreSubscriptionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->whereNull('deleted_at')],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
        ];
    }

    public function plan(): Plan
    {
        return Plan::query()->with('featureLimits')->findOrFail($this->integer('plan_id'));
    }

    public function cycle(): BillingCycle
    {
        return BillingCycle::from($this->string('billing_cycle')->toString());
    }
}
