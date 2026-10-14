<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A PDF the student uploaded to their own library, filed under one subject.
 *
 * Separate from {@see Book} by design: a platform book is admin-authored study
 * content with a publication status and an order inside its subject, while this
 * belongs to exactly one student and is never study content. Nothing that reads
 * platform books can reach these rows, and nothing here carries a status —
 * ownership is the only access rule there is.
 *
 * The PDF lives on the private disk, so unlike a platform book this model
 * exposes no public URL: the file is only ever handed over by the authenticated,
 * ownership-checked download route.
 *
 * Not soft-deleting, deliberately: when the student deletes a book they expect
 * the file to be gone, and the media library only cleans up after a model that
 * is really deleted.
 */
class StudentPrivateBook extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * Named apart from {@see Book::PDF_COLLECTION} so a student's upload and a
     * platform book's file can never land in the same collection.
     */
    public const PDF_COLLECTION = 'private_book_pdf';

    /**
     * The disk the uploaded file is written to: `local` is rooted outside the
     * public storage link, which is what keeps the file unreachable by URL.
     */
    public const PDF_DISK = 'local';

    protected $fillable = [
        'user_id',
        'subject_id',
        'title',
        'author',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PDF_COLLECTION)
            ->useDisk(self::PDF_DISK)
            ->acceptsMimeTypes(config('media.document_mime_types', []))
            ->singleFile();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function isOwnedBy(User $student): bool
    {
        return $this->user_id === $student->getKey();
    }

    public function pdf(): ?Media
    {
        return $this->getFirstMedia(self::PDF_COLLECTION);
    }

    public function hasPdf(): bool
    {
        return $this->pdf() instanceof Media;
    }

    public function pdfFileName(): ?string
    {
        return $this->pdf()?->file_name;
    }

    public function pdfSize(): ?int
    {
        $size = $this->pdf()?->size;

        return $size === null ? null : (int) $size;
    }

    public function pdfMimeType(): ?string
    {
        return $this->pdf()?->mime_type;
    }

    /**
     * @param  Builder<StudentPrivateBook>  $query
     * @return Builder<StudentPrivateBook>
     */
    public function scopeForStudent(Builder $query, User $student): Builder
    {
        return $query->where('user_id', $student->getKey());
    }
}
