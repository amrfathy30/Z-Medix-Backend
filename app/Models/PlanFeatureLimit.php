<?php

namespace App\Models;

use App\Enums\PlanFeature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One feature a plan includes. The row existing is what grants the feature;
 * `is_unlimited` / `limit` only describe how much of a limitable feature (AI
 * credits per paid period) the plan allows.
 */
class PlanFeatureLimit extends Model
{
    protected $fillable = [
        'plan_id',
        'feature',
        'is_unlimited',
        'limit',
    ];

    protected $attributes = [
        'is_unlimited' => false,
    ];

    protected function casts(): array
    {
        return [
            'feature' => PlanFeature::class,
            'is_unlimited' => 'boolean',
            'limit' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
