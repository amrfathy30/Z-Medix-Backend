<?php

namespace App\Support\Media;

/** Converts host-relative media URLs to absolute public API URLs. */
final class PublicMediaUrl
{
    public static function make(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $parts = parse_url($url);

        if (isset($parts['scheme']) || isset($parts['host'])) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }
}
