<?php

namespace App\Models;

use App\Enums\ContentStatus;
use Database\Factories\PageSectionItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\Attributes\Translatable;
use Spatie\Translatable\HasTranslations;

/** A reusable translatable, image-carrying item belonging to a page section. */
#[Translatable('title', 'description')]
class PageSectionItem extends Model implements HasMedia
{
    /** @use HasFactory<PageSectionItemFactory> */
    use HasFactory, HasTranslations, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'page_section_id',
        'title',
        'description',
        'icon',
        'link',
        'sort_order',
        'status',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return ['status' => ContentStatus::class, 'sort_order' => 'integer'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->useDisk('filament_public')->singleFile();
    }

    public function pageSection(): BelongsTo
    {
        return $this->belongsTo(PageSection::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
