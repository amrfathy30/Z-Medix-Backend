<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FcmTopicSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'topic' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9-_.~%]+$/'],
            'token' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
