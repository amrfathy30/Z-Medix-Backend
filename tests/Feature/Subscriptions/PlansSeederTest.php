<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\PlanFeature;
use App\Models\Plan;
use App\Models\PlanFeatureLimit;
use Database\Seeders\PlansSeeder;

class PlansSeederTest extends SubscriptionTestCase
{
    public function test_it_creates_basic_premium_and_ultra_with_the_agreed_features(): void
    {
        $this->seed(PlansSeeder::class);

        $features = fn (string $slug): array => Plan::query()
            ->where('slug', $slug)
            ->sole()
            ->orderedFeatureLimits()
            ->map(fn ($limit) => $limit->feature->value)
            ->all();

        $this->assertSame(['library', 'question_bank'], $features('basic'));
        $this->assertSame(['library', 'question_bank', 'case_report', 'ai'], $features('premium'));
        $this->assertSame(['library', 'question_bank', 'case_report', 'ai'], $features('ultra'));
    }

    public function test_premium_ai_is_limited_and_ultra_ai_is_unlimited(): void
    {
        $this->seed(PlansSeeder::class);

        $premiumAi = Plan::query()->where('slug', 'premium')->sole()->load('featureLimits')->limitFor(PlanFeature::Ai);
        $ultraAi = Plan::query()->where('slug', 'ultra')->sole()->load('featureLimits')->limitFor(PlanFeature::Ai);

        $this->assertFalse($premiumAi->is_unlimited);
        $this->assertGreaterThan(0, $premiumAi->limit);
        $this->assertTrue($ultraAi->is_unlimited);
        $this->assertNull($ultraAi->limit);
    }

    public function test_each_plan_sells_both_cycles(): void
    {
        $this->seed(PlansSeeder::class);

        Plan::query()->each(function (Plan $plan): void {
            $this->assertNotNull($plan->monthly_price, $plan->slug);
            $this->assertNotNull($plan->yearly_price, $plan->slug);
        });
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(PlansSeeder::class);

        $this->assertSame(3, Plan::query()->count());
        $this->assertSame(2 + 4 + 4, PlanFeatureLimit::query()->count());
    }

    public function test_it_never_overwrites_what_the_admin_edited(): void
    {
        $this->seed(PlansSeeder::class);

        $basic = Plan::query()->where('slug', 'basic')->sole();
        $basic->update(['monthly_price' => 1.23]);
        $basic->featureLimits()->where('feature', PlanFeature::QuestionBank->value)->delete();

        $this->seed(PlansSeeder::class);

        $basic->refresh()->load('featureLimits');
        $this->assertSame('1.23', $basic->monthly_price);
        $this->assertFalse($basic->hasFeature(PlanFeature::QuestionBank));
    }

    public function test_it_does_not_bring_back_a_plan_the_admin_deleted(): void
    {
        $this->seed(PlansSeeder::class);
        Plan::query()->where('slug', 'basic')->sole()->delete();

        $this->seed(PlansSeeder::class);

        $this->assertDatabaseMissing('plans', ['slug' => 'basic', 'deleted_at' => null]);
        $this->assertSame(1, Plan::withTrashed()->where('slug', 'basic')->count());
    }
}
