<?php

namespace App\Http\Requests\Api\Public\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyPasswordResetOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string', 'digits:'.$this->codeLength()],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => trim($this->email)]);
        }

        if (is_string($this->code)) {
            $this->merge(['code' => trim($this->code)]);
        }
    }

    private function codeLength(): int
    {
        return (int) config('auth_features.email_otp.length', 6);
    }
}
