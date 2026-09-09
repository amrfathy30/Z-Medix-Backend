<?php

namespace App\Support\Localization;

final class PublicLocales
{
    /** @return list<string> */
    public static function all(): array
    {
        return ['ar', 'en'];
    }

    public static function default(): string
    {
        return 'en';
    }

    public static function supports(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::all(), strict: true);
    }
}
