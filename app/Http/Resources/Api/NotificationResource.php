<?php

namespace App\Http\Resources\Api;

use App\Support\Notifications\NotificationLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];
        $viewer = $request->user();
        $locale = $viewer !== null ? NotificationLocale::forNotifiable($viewer) : app()->getLocale();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => $data['category'] ?? null,
            'title' => $this->renderText($data, 'title', $locale),
            'body' => $this->renderText($data, 'body', $locale) ?? ($data['message'] ?? null),
            'data' => $data,
            'action_url' => $data['action_url'] ?? null,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderText(array $data, string $field, string $locale): ?string
    {
        $key = $data["{$field}_key"] ?? null;

        return is_string($key) ? __($key, $data['replace'] ?? [], $locale) : ($data[$field] ?? null);
    }
}
