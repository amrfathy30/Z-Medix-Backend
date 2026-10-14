<?php

namespace App\Http\Resources\Api\Student;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subject opened: its chapter track and its books, side by side.
 *
 * The two are independent content types of the same subject — a book never
 * parents a chapter and a chapter never parents a book (business context §4) —
 * so they are returned as two lists rather than one nested inside the other.
 *
 * Chapters carry the state the client needs to draw the track: whether the
 * student has finished each one, and whether they may open it yet. Page bodies
 * are not here; the chapter and page endpoints serve those.
 *
 * @mixin Subject
 */
class SubjectDetailsResource extends JsonResource
{
    /**
     * @param  list<array<string, mixed>>  $chapters
     * @param  array{completed_chapters: int, total_chapters: int, percentage: float}  $chapterProgress
     * @param  array{completed_pages: int, total_pages: int, percentage: float}  $readingProgress
     */
    public function __construct(
        $resource,
        private array $chapters,
        private array $chapterProgress,
        private array $readingProgress,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'image' => $this->imageUrl(),
            'chapters_progress' => $this->chapterProgress,
            'reading_progress' => $this->readingProgress,
            'chapters' => $this->chapters,
            'books' => BookListItemResource::collection($this->publishedBooks),
        ];
    }
}
