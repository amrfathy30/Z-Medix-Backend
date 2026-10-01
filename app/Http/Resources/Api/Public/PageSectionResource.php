<?php

namespace App\Http\Resources\Api\Public;

use App\Models\Setting;
use App\Services\Localization\ArrayLocalizer;
use App\Services\Media\MediaUploadService;
use App\Support\Content\Media\ContentMediaUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PageSectionResource extends JsonResource
{
    /**
     * `data` keys served from public Site Settings instead of the section row,
     * as `"<page_key>.<section_key>" => [setting key, ...]`. The data key and the
     * setting key share a name, so the frontend contract is the same as for any
     * other `data` field.
     *
     * @var array<string, list<string>>
     */
    private const SETTING_BACKED_DATA = [
        'home.on_mobile' => ['app_store_url', 'google_play_url'],
    ];

    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        $localizedData = app(ArrayLocalizer::class)->localize($this->data ?? [], $locale);
        $localizedData = app(ContentMediaUrlResolver::class)->resolve($localizedData, $this->page?->key, $this->section_key);
        $localizedData = $this->withSettingBackedData($localizedData);

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withSettingBackedData(array $data): array
    {
        $keys = self::SETTING_BACKED_DATA["{$this->page?->key}.{$this->section_key}"] ?? [];

        if ($keys === []) {
            return $data;
        }

        $values = Setting::query()->where('is_public', true)->whereIn('key', $keys)->pluck('value', 'key');

        foreach ($keys as $key) {
            $data[$key] = filled($values[$key] ?? null) ? $values[$key] : null;
        }

        return $data;
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
