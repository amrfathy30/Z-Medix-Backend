<?php

namespace App\Http\Resources\Api\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FaqResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'question' => $this->getTranslation('question', $locale),
            'answer' => $this->getTranslation('answer', $locale),
            'sort_order' => $this->sort_order,
            'category' => new FaqCategoryResource($this->whenLoaded('category')),
        ];
    }
}
