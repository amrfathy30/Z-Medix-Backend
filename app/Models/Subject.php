<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Services\Media\MediaUploadService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Subject extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    public const IMAGE_COLLECTION = 'subject_image';

    protected $fillable = [
        'name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGE_COLLECTION)
            ->useDisk('filament_public')
            ->singleFile();
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * Platform books of this subject. Chapters and books are independent: a
     * book never parents a chapter, and a chapter never parents a book.
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class)
            ->orderBy('order')
            ->orderBy('id');
    }

    /**
     * Resolved public URL of the subject image, or null when none is uploaded.
     */
    public function imageUrl(): ?string
    {
        return app(MediaUploadService::class)->getUrl($this, self::IMAGE_COLLECTION);
    }

    /**
     * Placeholder until the Student Study/Progress module exists — there is no
     * student-to-subject relationship to count yet, so this never invents data.
     */
    public function studentsCount(): int
    {
        return 0;
    }
}
