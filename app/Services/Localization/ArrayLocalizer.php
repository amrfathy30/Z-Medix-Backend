<?php

namespace App\Services\Localization;

/**
 * Resolves bilingual {"en": ..., "ar": ...} leaves inside arbitrarily nested
 * arrays (e.g. the PageSection `data` column) down to a single scalar for
 * the current request locale, recursing through lists and objects alike.
 */
class ArrayLocalizer
{
    /** @param  array<mixed>  $data */
    public function localize(array $data, ?string $locale = null, ?string $fallbackLocale = null): array
    {
        $locale ??= app()->getLocale();
        $fallbackLocale ??= config('app.fallback_locale', 'en');

        /** @var array<mixed> */
        return $this->resolve($data, $locale, $fallbackLocale);
    }

    private function resolve(mixed $value, string $locale, string $fallbackLocale): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value) && $this->hasLocaleKeys($value)) {
            return $value[$locale] ?? $value[$fallbackLocale] ?? reset($value);
        }

        return array_map(fn (mixed $item): mixed => $this->resolve($item, $locale, $fallbackLocale), $value);
    }

    /** @param  array<mixed>  $value */
    private function hasLocaleKeys(array $value): bool
    {
        return array_key_exists('en', $value) || array_key_exists('ar', $value);
    }
}
