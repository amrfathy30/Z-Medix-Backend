<?php

namespace App\Models;

use App\Models\Concerns\HasDerivedPageNumber;
use App\Models\Concerns\HasSequentialOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A page of a content book. Like a chapter page it has no title: it is
 * identified by its position inside the book, read as "Page 1", "Page 2", …
 *
 * Only content books hold pages; a PDF book keeps its material in its single
 * uploaded file instead.
 */
class BookPage extends Model
{
    use HasDerivedPageNumber, HasFactory, HasSequentialOrder, SoftDeletes;

    protected $fillable = [
        'book_id',
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
        return 'book_id';
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * The page's 1-based position among the book's pages.
     */
    public function positionInBook(): int
    {
        return $this->positionAmongPages();
    }
}
