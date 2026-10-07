<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note a student wrote about a passage of a chapter page.
 *
 * `selected_text` is the passage the note was made against and is fixed once
 * written; `content` is the note itself and is the only part the student can
 * edit afterwards. The chapter and subject are reached through
 * {@see self::chapterPage()} rather than stored again here.
 */
class StudentNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'chapter_page_id',
        'selected_text',
        'content',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chapterPage(): BelongsTo
    {
        return $this->belongsTo(ChapterPage::class);
    }

    public function isOwnedBy(User $student): bool
    {
        return $this->user_id === $student->getKey();
    }

    /**
     * @param  Builder<StudentNote>  $query
     * @return Builder<StudentNote>
     */
    public function scopeForStudent(Builder $query, User $student): Builder
    {
        return $query->where('user_id', $student->getKey());
    }

    /**
     * @param  Builder<StudentNote>  $query
     * @return Builder<StudentNote>
     */
    public function scopeOnPage(Builder $query, ChapterPage $page): Builder
    {
        return $query->where('chapter_page_id', $page->getKey());
    }
}
