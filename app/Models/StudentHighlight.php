<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Text a student highlighted while reading a chapter page.
 *
 * There is one highlight style: no colour or appearance is stored. The selected
 * text is what is kept, so a highlight carries its own meaning away from the
 * page it was made on, and the chapter and subject it belongs to are reached
 * through {@see self::chapterPage()} rather than stored again here.
 */
class StudentHighlight extends Model
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
     * @param  Builder<StudentHighlight>  $query
     * @return Builder<StudentHighlight>
     */
    public function scopeForStudent(Builder $query, User $student): Builder
    {
        return $query->where('user_id', $student->getKey());
    }

    /**
     * @param  Builder<StudentHighlight>  $query
     * @return Builder<StudentHighlight>
     */
    public function scopeOnPage(Builder $query, ChapterPage $page): Builder
    {
        return $query->where('chapter_page_id', $page->getKey());
    }
}
