<?php

namespace App\Http\Requests\Api\Public;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'phone' => ['sometimes', 'string', 'max:32'],
            'country_id' => ['sometimes', 'integer', Rule::exists($this->countriesTable(), 'id')],
            'institution' => ['sometimes', 'nullable', 'string', 'max:255'],
            'field_of_study' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile_photo' => [
                'sometimes',
                'image',
                'mimetypes:'.implode(',', (array) config('media.image_mime_types', [])),
                'max:'.config('media.max_image_size_kb', 5 * 1024),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'full_name' => 'full name',
            'country_id' => 'country',
            'field_of_study' => 'field of study',
            'profile_photo' => 'profile photo',
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
        // Verification state is derived from backend data only; a client may never
        // submit it. Stripping here means the keys can never reach validated().
        $this->request->remove('email_verified');
        $this->request->remove('is_email_verified');
        $this->request->remove('email_verified_at');
        $this->request->remove('phone_verified');
        $this->request->remove('status');
        $this->request->remove('type');
    }

    /**
     * The verified email address cannot be changed here. Replacing it needs the
     * staged request-then-verify flow, which is not built yet, so an attempt to
     * change it is refused rather than silently ignored.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $submitted = $this->input('email');

            if (! is_string($submitted) || $submitted === '') {
                return;
            }

            if (trim($submitted) !== $this->user()->email) {
                $validator->errors()->add(
                    'email',
                    'Your email address cannot be changed here. Please use the email change flow.',
                );
            }
        });
    }

    /**
     * The profile payload with `full_name` folded into the stored `name` column
     * and the read-only email removed.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        $validated = $this->safe()->except(['email', 'phone', 'profile_photo', 'full_name']);

        if ($this->has('full_name')) {
            $validated['name'] = $this->string('full_name')->value();
        }

        return $validated;
    }

    private function countriesTable(): string
    {
        return (string) config('world.migrations.countries.table_name', 'countries');
    }
}
