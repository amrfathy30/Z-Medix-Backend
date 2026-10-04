<?php

namespace App\Http\Requests\Api\Public\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['required', 'string', 'max:32'],
            'country_id' => [
                'required',
                'integer',
                Rule::exists($this->countriesTable(), 'id'),
            ],
            'password' => ['required', 'string', 'confirmed', 'min:8'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'full_name' => 'full name',
            'country_id' => 'country',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'country_id.exists' => 'The selected country is not supported.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => trim($this->email)]);
        }
    }

    private function countriesTable(): string
    {
        return (string) config('world.migrations.countries.table_name', 'countries');
    }
}
