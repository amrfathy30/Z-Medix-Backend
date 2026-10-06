<?php

namespace App\Models;

use App\Enums\BookType;
use App\Enums\ContentStatus;
use App\Exceptions\Learning\BookTypeLockedException;
use App\Models\Concerns\HasSequentialOrder;
use App\Services\Media\MediaUploadService;
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

    protected $fillable = [
        'subject_id',
        'title',
        'author',
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
}
