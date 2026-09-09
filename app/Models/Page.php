<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Support\Localization\PublicLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\Attributes\Translatable;
use Spatie\Translatable\HasTranslations;

#[Translatable('meta_title', 'meta_description', 'public_path')]
class Page extends Model implements HasMedia
{
    use HasFactory, HasTranslations, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'key',
        'label',
        'meta_title',
        'meta_description',
        'public_path',
        'canonical_url',
        'is_indexable',
        'include_in_sitemap',
        'status',
        'published_at',
        'sort_order',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'sort_order' => 'integer',
            'published_at' => 'datetime',
            'is_indexable' => 'boolean',
            'include_in_sitemap' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Page $page): void {
            if ($page->status === ContentStatus::Published && $page->published_at === null) {
                $page->published_at = now();
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->useDisk('filament_public');
        $this->addMediaCollection('banner')->useDisk('filament_public')->singleFile();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class, 'page_id');
    }

    public function scopeByKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ContentStatus::Published);
    }

    public function scopeSitemapEligible(Builder $query): Builder
    {
        return $query->published()
            ->where('is_indexable', true)
            ->where('include_in_sitemap', true)
            ->where(fn (Builder $q) => $q->whereNotNull('public_path')->orWhereNotNull('canonical_url'));
    }

    public function resolvedPublicUrl(?string $locale = null): ?string
    {
        if (filled($this->canonical_url)) {
            return $this->canonical_url;
        }

        $path = $this->getTranslation('public_path', $locale ?? app()->getLocale(), false);

        return filled($path) ? self::absolutePublicUrl($path) : null;
    }

    /** @return array<string, string> */
    public function alternateUrls(): array
    {
        $paths = $this->getTranslations('public_path');
        $urls = [];

        foreach (PublicLocales::all() as $locale) {
            $path = $paths[$locale] ?? null;

            if (filled($path)) {
                $urls[$locale] = self::absolutePublicUrl($path);
            }
        }

        return $urls;
    }

    private static function absolutePublicUrl(string $path): string
    {
        return rtrim((string) config('seo.public_base_url'), '/').'/'.ltrim($path, '/');
    }
}
