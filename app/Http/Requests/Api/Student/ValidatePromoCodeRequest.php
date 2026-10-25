<?php

namespace App\Http\Requests\Api\Student;

class ValidatePromoCodeRequest extends StoreSubscriptionRequestRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), ['promo_code' => ['required', 'string', 'max:64']]);
    }
}
