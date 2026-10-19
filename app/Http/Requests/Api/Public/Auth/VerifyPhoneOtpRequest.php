<?php

namespace App\Http\Requests\Api\Public\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Carries only the submitted code. The number being verified is the
 * authenticated student's primary one, resolved server-side.
 */
class VerifyPhoneOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits_between:4,10'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'The verification code is required.',
            'code.digits_between' => 'The verification code must be numeric.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // As with the send endpoint, the target number may never come from the
        // client, and verification state is derived from backend data only.
        $this->request->remove('phone');
        $this->request->remove('phone_id');
        $this->request->remove('e164_number');
        $this->request->remove('phone_verified');

        if (is_string($this->code)) {
            $this->merge(['code' => trim($this->code)]);
        }
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }
}
