<?php

namespace App\Http\Requests\Api\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAiMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $seen = [];
            foreach ($this->validated('attachments', []) as $index => $reference) {
                $key = $reference['type'].':'.(int) $reference['id'];
                if (isset($seen[$key])) {
                    $validator->errors()->add("attachments.{$index}.id", 'Duplicate attachment reference.');
                }
                $seen[$key] = true;
            }
        }];
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'attachments' => ['sometimes', 'array', 'list', 'max:'.config('ai_chat.max_attachments')],
            'attachments.*' => ['required', 'array:type,id'],
            'attachments.*.type' => ['required', Rule::in(['book', 'chapter', 'highlight', 'note'])],
            'attachments.*.id' => ['required', 'integer', 'min:1'],
            'content' => ['required', 'string', 'max:'.config('ai_chat.max_message_length')],
            'client_message_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }
}
