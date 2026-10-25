<?php

namespace App\Http\Requests\Api\Public\Auth;

use Illuminate\Validation\Rule;

class ChangePhoneRequest extends SendPhoneOtpRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->phone)) {
            $this->merge(['phone' => trim($this->phone)]);
        }

        if (is_string($this->channel)) {
            $this->merge(['channel' => mb_strtolower(trim($this->channel))]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            'channel' => ['sometimes', 'string', Rule::in(config('phone.supported_channels', ['sms']))],
        ];
    }
}
