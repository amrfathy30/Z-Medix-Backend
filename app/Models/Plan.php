<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\PlanFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\Attributes\Translatable;
use Spatie\Translatable\HasTranslations;

/**
 * A plan the admin sells: a set of features (some with a limit) and one price
 * per billing cycle. A null price means that cycle is not offered.
 */
#[Translatable('name', 'description')]
class Plan extends Model
{
    use HasFactory, HasTranslations, SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'monthly_price',
        'yearly_price',
        'currency',
        'is_active',
        'sort_order',
    ];

    protected $attributes = [
        'currency' => 'USD',
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'yearly_price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function featureLimits(): HasMany
    {
        return $this->hasMany(PlanFeatureLimit::class);
    }

    /**
     * Plans a student can currently pick, in the order the admin arranged them.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function priceFor(BillingCycle $cycle): ?string
    {
        return match ($cycle) {
            BillingCycle::Monthly => $this->monthly_price,
            BillingCycle::Yearly => $this->yearly_price,
        };
    }

    public function offers(BillingCycle $cycle): bool
    {
        return $this->priceFor($cycle) !== null;
    }

    /**
     * The plan's features in the order the product lists them, not the order
     * they happened to be saved in.
     *
     * @return Collection<int, PlanFeatureLimit>
     */
    public function orderedFeatureLimits(): Collection
    {
        $order = array_flip(array_column(PlanFeature::cases(), 'value'));

        return $this->featureLimits
            ->sortBy(fn (PlanFeatureLimit $limit): int => $order[$limit->feature->value])
            ->values();
    }

    public function limitFor(PlanFeature $feature): ?PlanFeatureLimit
    {
        return $this->featureLimits->firstWhere('feature', $feature);
    }

    public function hasFeature(PlanFeature $feature): bool
    {
        return $this->limitFor($feature) !== null;
    }
}
