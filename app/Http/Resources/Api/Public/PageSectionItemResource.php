<?php

namespace App\Http\Resources\Api\Public;

use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageSectionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'title' => $this->title !== null ? $this->getTranslation('title', $locale) : null,
            'description' => $this->description !== null ? $this->getTranslation('description', $locale) : null,
            'icon' => $this->icon,
            'link' => $this->link,
            'image_url' => PublicMediaUrl::make($this->getFirstMedia('image')?->getFullUrl()),
        ];
    }
}
