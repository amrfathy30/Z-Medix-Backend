<?php

namespace App\Enums\Marketing;

/**
 * The marketing platforms whose tracking pixel the frontend may install.
 *
 * The single declaration of the platform codes — the `marketing_pixels`
 * table stores exactly one row per case.
 *
 * `maxLength()` and `pattern()` live here rather than on the Filament page
 * because they describe the platform's own ID format, not the form.
 */
enum MarketingPixelPlatform: string
{
    case Facebook = 'facebook';
    case X = 'x';
    case TikTok = 'tiktok';

    /**
     * The longest ID the platform issues, with headroom. Used as the
     * column's practical limit and as the form's `maxLength`.
     */
    public function maxLength(): int
    {
        return match ($this) {
            self::Facebook => 32,
            self::X => 16,
            self::TikTok => 64,
        };
    }

    /**
     * The accepted shape of the ID, anchored and whitespace-free.
     *
     * Meta issues purely numeric pixel IDs (15-16 digits today, so the
     * bound is deliberately loose rather than an exact length that a later
     * Meta change would break). X and TikTok issue alphanumeric identifiers.
     */
    public function pattern(): string
    {
        return match ($this) {
            self::Facebook => '/^\d{6,32}$/',
            self::X => '/^[A-Za-z0-9]{4,16}$/',
            self::TikTok => '/^[A-Za-z0-9]{8,64}$/',
        };
    }

    /** A representative ID, shown as the field's placeholder. */
    public function placeholder(): string
    {
        return match ($this) {
            self::Facebook => '123456789012345',
            self::X => 'o1a2b',
            self::TikTok => 'C4A1B2C3D4E5F6G7H8I9',
        };
    }
}
