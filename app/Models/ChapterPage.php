<?php

namespace App\Models;

use App\Models\Concerns\HasDerivedPageNumber;
use App\Models\Concerns\HasSequentialOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A study page has no title: it is identified by its position inside the
 * chapter, which the admin reads as "Page 1", "Page 2", and so on.
 */
class ChapterPage extends Model
{
    use HasDerivedPageNumber, HasFactory, HasSequentialOrder, SoftDeletes;

    protected $fillable = [
        'chapter_id',
        'content',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    public function orderParentColumn(): string
    {
        return 'chapter_id';
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * Every student's highlights on this page. Student endpoints always read
     * through {@see StudentHighlight::scopeForStudent()} instead.
     */
    public function highlights(): HasMany
    {
        return $this->hasMany(StudentHighlight::class);
    }

    /**
     * Every student's notes on this page. Student endpoints always read through
     * {@see StudentNote::scopeForStudent()} instead.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(StudentNote::class);
    }

    /**
     * The page's 1-based position among the chapter's pages.
     */
    public function positionInChapter(): int
    {
        return $this->positionAmongPages();
    }
}
