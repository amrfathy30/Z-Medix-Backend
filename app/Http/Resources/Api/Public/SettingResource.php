<?php

namespace App\Http\Resources\Api\Public;

use App\Enums\SettingValueType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

class SettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'value' => $this->resolveValue(),
            'group' => $this->group,
        ];
    }

    private function resolveValue(): mixed
    {
        if ($this->value_type === SettingValueType::Json) {
            $decoded = json_decode($this->value, true);

            if (is_array($decoded) && array_key_exists(App::getLocale(), $decoded)) {
                return $decoded[App::getLocale()];
            }

            return $decoded;
        }

        if ($this->value_type === SettingValueType::Image) {
            return filled($this->value) ? Storage::disk('public')->url($this->value) : null;
        }

        return $this->value;
    }
}
