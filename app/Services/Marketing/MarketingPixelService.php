<?php

namespace App\Services\Marketing;

use App\Models\MarketingPixel;
use Illuminate\Support\Facades\Cache;

/**
 * The one place the published pixel payload is assembled.
 *
 * The public endpoint is unauthenticated and called on essentially every
 * frontend page load, so the answer is cached; MarketingPixel drops that
 * cache on every write, which is why the TTL below is a backstop rather
 * than the mechanism keeping it fresh.
 */
class MarketingPixelService
{
    public const CACHE_KEY = 'marketing_pixels.public';

    /**
     * One hour. Only a backstop against a cache that outlived its
     * invalidation: an ordinary save publishes immediately via flush().
     */
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Every installable pixel, keyed by platform code.
     *
     * @return array<string, string>
     */
    public function publicPixels(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => MarketingPixel::query()
                ->publishable()
                ->orderBy('platform')
                ->pluck('pixel_id', 'platform')
                ->map(fn (string $pixelId): string => (string) $pixelId)
                ->all(),
        );
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
