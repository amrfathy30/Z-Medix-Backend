<?php

namespace Database\Seeders;

use App\Enums\PlanFeature;
use App\Models\Plan;
use App\Models\PlanFeatureLimit;
use Illuminate\Database\Seeder;

/**
 * The three plans the platform launches with: Basic, Premium and Ultra.
 *
 * Safe to run repeatedly and on a live database: a plan is created only when its
 * slug is missing, so prices and features the admin has since edited in the
 * dashboard are never overwritten. Prices reuse the landing-page placeholders
 * and the Premium AI allowance is a placeholder too — set the real ones from
 * Plans in the dashboard.
 */
class PlansSeeder extends Seeder
{
    /** Premium's AI credits per paid period, until the admin sets the real figure. */
    private const PREMIUM_AI_CREDITS = 100;

    public function run(): void
    {
        $plans = [
            [
                'slug' => 'basic',
                'name' => ['en' => 'Basic', 'ar' => 'الأساسية'],
                'monthly_price' => 5.99,
                'yearly_price' => 59.99,
                'sort_order' => 1,
                'features' => [PlanFeature::Library, PlanFeature::QuestionBank],
            ],
            [
                'slug' => 'premium',
                'name' => ['en' => 'Premium', 'ar' => 'المميزة'],
                'monthly_price' => 9.99,
                'yearly_price' => 99.99,
                'sort_order' => 2,
                'features' => [PlanFeature::Library, PlanFeature::QuestionBank, PlanFeature::CaseReport],
                'ai' => ['is_unlimited' => false, 'limit' => self::PREMIUM_AI_CREDITS],
            ],
            [
                'slug' => 'ultra',
                'name' => ['en' => 'Ultra', 'ar' => 'ألترا'],
                'monthly_price' => 25.99,
                'yearly_price' => 259.99,
                'sort_order' => 3,
                'features' => [PlanFeature::Library, PlanFeature::QuestionBank, PlanFeature::CaseReport],
                'ai' => ['is_unlimited' => true, 'limit' => null],
            ],
        ];

        foreach ($plans as $definition) {
            $features = $definition['features'];
            $ai = $definition['ai'] ?? null;
            unset($definition['features'], $definition['ai']);

            $plan = Plan::withTrashed()->where('slug', $definition['slug'])->first();

            // Already there (possibly edited, possibly deleted on purpose): leave it.
            if ($plan !== null) {
                continue;
            }

            $plan = Plan::query()->create($definition);

            foreach ($features as $feature) {
                PlanFeatureLimit::query()->create([
                    'plan_id' => $plan->getKey(),
                    'feature' => $feature,
                ]);
            }

            if ($ai !== null) {
                PlanFeatureLimit::query()->create([
                    'plan_id' => $plan->getKey(),
                    'feature' => PlanFeature::Ai,
                    ...$ai,
                ]);
            }
        }
    }
}
