<?php

namespace App\Models;

use App\Enums\BookType;
use App\Enums\ContentStatus;
use App\Exceptions\Learning\BookTypeLockedException;
use App\Models\Concerns\HasSequentialOrder;
use App\Services\Media\MediaUploadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A platform book: admin-authored study material belonging to one subject,
 * either a single uploaded PDF or a sequence of rich-text pages.
 *
 * Student-owned private books are a separate future concern and never share
 * these records.
 */
class Book extends Model implements HasMedia
{
    use HasFactory, HasSequentialOrder, InteractsWithMedia, SoftDeletes;

    public const PDF_COLLECTION = 'book_pdf';

    public const COVER_COLLECTION = 'book_cover';

    protected $fillable = [
        'subject_id',
        'title',
        'author',
        'classification',
        'total_pages',
        'type',
        'status',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'type' => BookType::class,
            'status' => ContentStatus::class,
            'order' => 'integer',
            'total_pages' => 'integer',
        ];
    }

    /**
     * The type decides which kind of content a book may hold, so it is frozen
     * once content exists under it. The guard lives on the model rather than
     * only on the admin form, so a crafted request cannot strand a PDF on a
     * content book or pages on a PDF book.
     */
    protected static function booted(): void
    {
        static::updating(function (Book $book): void {
            if (! $book->isDirty('type')) {
                return;
            }

            $previousType = $book->getOriginal('type');
            $previousType = $previousType instanceof BookType
                ? $previousType
                : BookType::tryFrom((string) $previousType);

            if ($previousType === BookType::Pdf && $book->hasPdf()) {
                throw BookTypeLockedException::pdfAlreadyUploaded();
            }

            if ($previousType === BookType::Content && $book->pagesCount() > 0) {
                throw BookTypeLockedException::pagesAlreadyAdded();
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PDF_COLLECTION)
            ->useDisk('filament_public')
            ->acceptsMimeTypes(config('media.document_mime_types', []))
            ->singleFile();

        // The book's cover, independent of its material: a book of either type
        // may carry one, and it never affects the type lock above.
        $this->addMediaCollection(self::COVER_COLLECTION)
            ->useDisk('filament_public')
            ->acceptsMimeTypes(config('media.cover_image_mime_types', []))
            ->singleFile();
    }

    public function orderParentColumn(): string
    {
        return 'subject_id';
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(BookPage::class)
            ->orderBy('order')
            ->orderBy('id');
    }

    public function isPdf(): bool
    {
        return $this->type === BookType::Pdf;
    }

    public function isContent(): bool
    {
        return $this->type === BookType::Content;
    }

    public function hasPdf(): bool
    {
        return $this->getFirstMedia(self::PDF_COLLECTION) instanceof Media;
    }

    public function pagesCount(): int
    {
        return $this->pages()->count();
    }

    /**
     * Resolved public URL of the uploaded PDF, or null when none is uploaded.
     */
    public function pdfUrl(): ?string
    {
        return app(MediaUploadService::class)->getUrl($this, self::PDF_COLLECTION);
    }

    public function pdfFileName(): ?string
    {
        return $this->getFirstMedia(self::PDF_COLLECTION)?->file_name;
    }

    public function hasCover(): bool
    {
        return $this->getFirstMedia(self::COVER_COLLECTION) instanceof Media;
    }

    /**
     * Resolved public URL of the cover image, or null when none is uploaded.
     */
    public function coverUrl(): ?string
    {
        return app(MediaUploadService::class)->getUrl($this, self::COVER_COLLECTION);
    }

    /**
     * How many pages the book has, whichever kind it is.
     *
     * A content book is counted from its page rows. A PDF book keeps its
     * material in one file that nothing here parses, so its figure is the one
     * the admin typed, and null until they do — never a zero, which would claim
     * an empty book.
     */
    public function totalPages(): ?int
    {
        return $this->isContent()
            ? (int) ($this->pages_count ?? $this->pagesCount())
            : $this->total_pages;
    }

    /**
     * False once content exists under the book, which freezes its type.
     */
    public function canChangeType(): bool
    {
        return ! $this->hasContent();
    }

    /**
     * Whether any content exists under the book. This gates the type only —
     * status is an administrative state and never depends on it, so an empty
     * book is a valid draft, published or archived book.
     */
    public function hasContent(): bool
    {
        return $this->hasPdf() || $this->pagesCount() > 0;
    }

    /**
     * Only published books are student-facing content. Mirrors
     * {@see Chapter::scopePublished()} so both kinds of subject content answer
     * the question the same way.
     *
     * @param  Builder<Book>  $query
     * @return Builder<Book>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published);
    }

    public function isPublished(): bool
    {
        return $this->status === ContentStatus::Published;
    }
}
