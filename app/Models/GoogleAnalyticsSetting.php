<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The Google Analytics (GA4) integration's one settings row.
 *
 * `enabled`/`measurement_id` are read by the public analytics endpoint;
 * `api_secret`/`property_id`/`service_account_json` never are — that split
 * is fixed by which columns each consumer selects, not a per-row flag.
 *
 * The single row is created by the create-table migration and only ever
 * updated afterwards — current() is the one place that assumption lives.
 */
class GoogleAnalyticsSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'enabled',
        'measurement_id',
        'api_secret',
        'property_id',
        'service_account_json',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->findOrFail(1);
    }
}
