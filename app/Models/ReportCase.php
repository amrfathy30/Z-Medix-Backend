<?php

namespace App\Models;

use App\Services\Media\MediaUploadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * An admin-authored report case: a short write-up plus the source PDF students
 * read it from. The PDF itself lives in the report_case_pdf media collection,
 * never as a path on the record.
 */
class ReportCase extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    public const PDF_COLLECTION = 'report_case_pdf';

    protected $fillable = [
        'title',
        'description',
        'source',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PDF_COLLECTION)
            ->useDisk('filament_public')
            ->acceptsMimeTypes(config('media.document_mime_types', []))
            ->singleFile();
    }

    /**
     * Only active report cases are ever exposed to students.
     *
     * @param  Builder<ReportCase>  $query
     * @return Builder<ReportCase>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasPdf(): bool
    {
        return $this->getFirstMedia(self::PDF_COLLECTION) instanceof Media;
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
}
