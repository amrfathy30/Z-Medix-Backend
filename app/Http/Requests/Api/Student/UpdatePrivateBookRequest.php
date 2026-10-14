<?php

namespace App\Http\Requests\Api\Student;

use App\Models\Subject;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing a private book's metadata. Every field is optional, so a client may
 * send only what changed; the uploaded PDF is not replaceable here.
 */
class UpdatePrivateBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'subject_id' => ['sometimes', 'integer', 'exists:subjects,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            // Explicitly nullable: clearing the author is a legitimate edit.
            'author' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Only the keys the client actually sent, so an absent field is left alone
     * rather than overwritten with null.
     *
     * @return array{title?: string, author?: string|null, subject?: Subject}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('title')) {
            $changes['title'] = $this->string('title')->toString();
        }

        if ($this->has('author')) {
            $author = $this->string('author')->trim()->toString();
            $changes['author'] = $author === '' ? null : $author;
        }

        if ($this->has('subject_id')) {
            $changes['subject'] = Subject::query()->findOrFail($this->integer('subject_id'));
        }

        return $changes;
    }
}
