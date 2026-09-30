<?php

namespace App\Models;

use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PageSection extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'page_id',
        'section_key',
        'label',
        'data',
        'sort_order',
        'status',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'sort_order' => 'integer',
            'status' => ContentStatus::class,
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('background')->useDisk('filament_public')->singleFile();
        $this->addMediaCollection('image')->useDisk('filament_public')->singleFile();
        $this->addMediaCollection('image_small')->useDisk('filament_public')->singleFile();
        $this->addMediaCollection('logo')->useDisk('filament_public')->singleFile();
        $this->addMediaCollection('icon')->useDisk('filament_public')->singleFile();
        $this->addMediaCollection('gallery')->useDisk('filament_public');
        $this->addMediaCollection('video')->useDisk('filament_public')->singleFile();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PageSectionItem::class);
    }

    /** Resolves the Page by key, then queries purely by the relational page_id. */
    public function scopeForPageKey(Builder $query, string $pageKey): Builder
    {
        $page = Page::query()->where('key', $pageKey)->first();

        if ($page === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('page_id', $page->id);
    }

    /**
     * Preferred over scopeForPageKey when the Page is already resolved —
     * queries purely by the relational page_id. Named scopeOfPage rather
     * than scopeForPage because Eloquent's built-in forPage($page, $perPage)
     * pagination method takes priority over any local scope of that exact
     * name, silently swallowing it — Filament's table pagination calls
     * forPage() internally, which broke every Livewire-rendered table page.
     */
    public function scopeOfPage(Builder $query, Page $page): Builder
    {
        return $query->where('page_id', $page->id);
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
