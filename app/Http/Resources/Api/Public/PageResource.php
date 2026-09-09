<?php

namespace App\Http\Resources\Api\Public;

use App\Enums\ContentStatus;
use App\Services\Localization\ArrayLocalizer;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Since Phase 3H, pages.title/content/meta_title/meta_description/slug no
 * longer exist — a page's title/body/meta_title/meta_description are read
 * exclusively from its `content` Website Content section's `data`. Since
 * Phase 3I, the response exposes `key` only — `slug` is gone, with no
 * fallback.
 */
class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        $section = $this->resource->sections()
            ->where('section_key', 'content')
            ->where('status', ContentStatus::Published)
            ->first();

        $data = $section !== null
            ? app(ArrayLocalizer::class)->localize($section->data ?? [], $locale)
            : [];

        return [
            'key' => $this->key,
            'title' => $data['title'] ?? $this->label,
            'content' => $data['body'] ?? null,
            'meta_title' => $this->translated('meta_title', $locale) ?? $data['meta_title'] ?? null,
            'meta_description' => $this->translated('meta_description', $locale) ?? $data['meta_description'] ?? null,
            'canonical_url' => $this->resource->resolvedPublicUrl($locale),
            'robots' => [
                'index' => (bool) ($this->resource->is_indexable ?? true),
                'follow' => true,
            ],
            'alternates' => collect($this->resource->alternateUrls())
                ->map(fn (string $url, string $hreflang): array => ['hreflang' => $hreflang, 'url' => $url])
                ->values()
                ->all(),
            'banner_url' => PublicMediaUrl::make($this->getFirstMedia('banner')?->getFullUrl()),
            'published_at' => $this->published_at?->toIso8601String(),
            'sections' => PageSectionResource::collection($this->resource->sections),
        ];
    }

    private function translated(string $attribute, string $locale): ?string
    {
        $value = $this->resource->getTranslation($attribute, $locale);

        return filled($value) ? $value : null;
    }
}
