<?php

namespace App\Http\Requests\Api\Public\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The phone number is never taken from the request: the endpoint always acts on
 * the authenticated student's primary number. Only the delivery channel is
 * negotiable, and only within the configured allow-list.
 */
class SendPhoneOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'channel' => ['sometimes', 'string', Rule::in($this->supportedChannels())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'channel.in' => 'The selected delivery channel is not supported.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // The number is server-resolved; accepting one here would let a client
        // aim the OTP at a number they do not own.
        $this->request->remove('phone');
        $this->request->remove('phone_id');
        $this->request->remove('e164_number');

        if (is_string($this->channel)) {
            $this->merge(['channel' => mb_strtolower(trim($this->channel))]);
        }
    }

    public function channel(): ?string
    {
        $channel = $this->validated('channel');

        return is_string($channel) && $channel !== '' ? $channel : null;
    }

    /** @return list<string> */
    private function supportedChannels(): array
    {
        /** @var list<string> $channels */
        $channels = config('phone.supported_channels', ['sms']);

        return $channels;
    }
}
