<?php

namespace App\Models;

use App\Enums\Marketing\MarketingPixelPlatform;
use App\Services\Marketing\MarketingPixelService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One marketing platform's tracking pixel.
 *
 * `pixel_id` and `is_public` are INDEPENDENT by design: storing an ID does
 * not publish it, and switching a platform off does not discard its ID — an
 * admin switches it back on later without retyping anything.
 *
 * Rows are created by the create-table migration, one per
 * MarketingPixelPlatform case, and are only ever UPDATED afterwards.
 */
class MarketingPixel extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'pixel_id',
        'is_public',
        'meta_capi_enabled',
        'meta_capi_access_token',
        'meta_capi_access_token_last4',
        'meta_capi_test_mode',
        'meta_capi_test_event_code',
    ];

    protected $hidden = [
        'meta_capi_access_token',
    ];

    protected function casts(): array
    {
        return [
            'platform' => MarketingPixelPlatform::class,
            'is_public' => 'boolean',
            'meta_capi_enabled' => 'boolean',
            'meta_capi_access_token' => 'encrypted',
            'meta_capi_test_mode' => 'boolean',
        ];
    }

    /**
     * The published payload is cached, so any write to a row has to drop it.
     * Hung on the model so a console command or tinker session can never
     * leave the endpoint serving a stale pixel. `saved` covers create and
     * update alike.
     */
    protected static function booted(): void
    {
        static::saved(function (): void {
            app(MarketingPixelService::class)->flush();
        });

        static::deleted(function (): void {
            app(MarketingPixelService::class)->flush();
        });
    }

    /**
     * The rows the public endpoint may publish: switched on AND actually
     * carrying an ID.
     *
     * @param  Builder<MarketingPixel>  $query
     */
    public function scopePublishable(Builder $query): void
    {
        $query->where('is_public', true)
            ->whereNotNull('pixel_id')
            ->where('pixel_id', '!=', '');
    }
}
