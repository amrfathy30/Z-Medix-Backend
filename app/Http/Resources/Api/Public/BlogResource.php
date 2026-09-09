<?php

namespace App\Http\Resources\Api\Public;

use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'slug' => $this->slug,
            'title' => $this->getTranslation('title', $locale),
            'excerpt' => $this->getTranslation('excerpt', $locale),
            'content' => $this->getTranslation('content', $locale),
            'meta_title' => $this->getTranslation('meta_title', $locale),
            'meta_description' => $this->getTranslation('meta_description', $locale),
            'cover_url' => PublicMediaUrl::make($this->getFirstMedia('cover')?->getFullUrl()),
            'category' => new BlogCategoryResource($this->whenLoaded('category')),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
