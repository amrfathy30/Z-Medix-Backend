<?php

namespace App\Http\Resources\Api\Student\Concerns;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Subject;

/**
 * Where an annotation sits, for the screens that list a student's highlights,
 * notes and bookmarks across their whole library.
 *
 * The per-page endpoints never need this — the client already knows the page it
 * asked about — so the context is opt-in and those payloads are unchanged. The
 * collection screens do need it: a passage on its own gives the student no way
 * back to the chapter it came from.
 *
 * Reads only relations the caller eager-loaded, and degrades to null rather
 * than querying per row, so a listing cannot become N+1 by being rendered.
 */
trait HasAnnotationContext
{
    /**
     * @return array<string, mixed>|null
     */
    protected function annotationContext(): ?array
    {
        $page = $this->resource->relationLoaded('chapterPage')
            ? $this->resource->getRelation('chapterPage')
            : null;

        if (! $page instanceof ChapterPage) {
            return null;
        }

        $chapter = $page->relationLoaded('chapter') ? $page->getRelation('chapter') : null;
        $subject = $chapter instanceof Chapter && $chapter->relationLoaded('subject')
            ? $chapter->getRelation('subject')
            : null;

        return [
            'page' => [
                'id' => $page->getKey(),
                'position' => $page->positionInChapter(),
            ],
            'chapter' => $chapter instanceof Chapter ? [
                'id' => $chapter->getKey(),
                'title' => $chapter->title,
                'order' => $chapter->order,
            ] : null,
            'subject' => $subject instanceof Subject ? [
                'id' => $subject->getKey(),
                'name' => $subject->name,
            ] : null,
        ];
    }
}
