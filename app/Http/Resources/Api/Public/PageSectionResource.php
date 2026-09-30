<?php

namespace App\Http\Resources\Api\Public;

use App\Services\Localization\ArrayLocalizer;
use App\Services\Media\MediaUploadService;
use App\Support\Content\Media\ContentMediaUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PageSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        $localizedData = app(ArrayLocalizer::class)->localize($this->data ?? [], $locale);
        $localizedData = app(ContentMediaUrlResolver::class)->resolve($localizedData, $this->page?->key, $this->section_key);

        $title = $localizedData['title'] ?? null;
        $subtitle = $localizedData['subtitle'] ?? null;
        $body = $localizedData['body'] ?? null;

        // data.cta is the canonical CTA shape; the legacy flat
        // button_text/button_url keys are a read-only fallback only.
        $cta = $localizedData['cta'] ?? null;
        $buttonText = $cta['text'] ?? $localizedData['button_text'] ?? null;
        $buttonUrl = $cta['url'] ?? $localizedData['button_url'] ?? null;

        return [
            'id' => $this->id,
            'page_key' => $this->page?->key,
            'section_key' => $this->section_key,
            'title' => $title,
            'subtitle' => $subtitle,
            'body' => $body,
            'button_text' => $buttonText,
            'button_url' => $buttonUrl,
            'data' => Arr::except($localizedData, ['title', 'subtitle', 'body', 'button_text', 'button_url']),
            'items' => PageSectionItemResource::collection($this->whenLoaded('items')),
            'sort_order' => $this->sort_order,
            'media' => [
                'background' => $this->mediaPayload('background'),
                'image' => $this->mediaPayload('image'),
                'image_small' => $this->mediaPayload('image_small'),
                'logo' => $this->mediaPayload('logo'),
                'icon' => $this->mediaPayload('icon'),
                'gallery' => app(MediaUploadService::class)->collectionToPayload($this->resource, 'gallery'),
                'video' => $this->mediaPayload('video'),
            ],
        ];
    }

    private function mediaPayload(string $collection): ?array
    {
        $media = $this->getFirstMedia($collection);

        if (! $media instanceof Media) {
            return null;
        }

        return app(MediaUploadService::class)->toPayload($media);
    }
}
