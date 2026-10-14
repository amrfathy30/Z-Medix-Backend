<?php

namespace App\Http\Requests\Api\Student;

use App\Models\Chapter;
use App\Models\Subject;
use App\Services\Learning\StudyAccessService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The filters the dedicated Highlights, Notes and Bookmarks screens offer:
 * everything, one subject, or one chapter.
 *
 * `exists` only establishes that the id names a real row. Whether the student
 * may see anything under it is settled by
 * {@see StudyAccessService::accessibleChapterIds()},
 * which is why an inaccessible subject yields an empty page here rather than a
 * validation error — a student is never told whether content they cannot reach
 * exists.
 */
class AnnotationCollectionRequest extends FormRequest
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
            'chapter_id' => ['sometimes', 'integer', 'exists:chapters,id'],
        ];
    }

    public function subject(): ?Subject
    {
        $id = $this->integer('subject_id');

        return $id === 0 ? null : Subject::query()->find($id);
    }

    public function chapter(): ?Chapter
    {
        $id = $this->integer('chapter_id');

        return $id === 0 ? null : Chapter::query()->find($id);
    }

    /**
     * Clamped rather than rejected, which is how every other paginated endpoint
     * in the application treats it: an out-of-range page size is served at the
     * ceiling instead of failing the request.
     */
    public function perPage(): int
    {
        $perPage = $this->integer('per_page', 15);

        return $perPage < 1 ? 15 : min($perPage, 50);
    }
}
