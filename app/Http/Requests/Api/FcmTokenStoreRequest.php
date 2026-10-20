<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FcmTokenStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:1000'],
            'device_type' => ['nullable', 'string', Rule::in(['ios', 'android', 'web'])],
            'device_id' => ['nullable', 'string', 'max:255'],
            'app_type' => ['nullable', 'string', 'max:50'],
        ];
    }
}
