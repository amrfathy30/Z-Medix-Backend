<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passage of a chapter page a student bookmarked to return to.
 *
 * Deliberately the same shape as {@see StudentHighlight}: the selected text is
 * what is kept, so a bookmark carries its own meaning away from the page it was
 * made on, and the chapter and subject it belongs to are reached through
 * {@see self::chapterPage()} rather than stored again here.
 *
 * A bookmark is not a highlight and not a note: it marks a place to come back
 * to, carries no writing of the student's own, and is therefore created and
 * removed but never edited.
 */
class StudentBookmark extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'chapter_page_id',
        'selected_text',
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
     * @param  Builder<StudentBookmark>  $query
     * @return Builder<StudentBookmark>
     */
    public function scopeForStudent(Builder $query, User $student): Builder
    {
        return $query->where('user_id', $student->getKey());
    }

    /**
     * @param  Builder<StudentBookmark>  $query
     * @return Builder<StudentBookmark>
     */
    public function scopeOnPage(Builder $query, ChapterPage $page): Builder
    {
        return $query->where('chapter_page_id', $page->getKey());
    }
}
