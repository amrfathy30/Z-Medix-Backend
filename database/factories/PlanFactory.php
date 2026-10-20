<?php

namespace Database\Factories;

use App\Enums\PlanFeature;
use App\Models\Plan;
use App\Models\PlanFeatureLimit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => ['en' => Str::title($name), 'ar' => 'باقة '.$name],
            'description' => ['en' => fake()->sentence(), 'ar' => fake()->sentence()],
            'monthly_price' => 9.99,
            'yearly_price' => 99.99,
            'currency' => 'USD',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /** A plan sold monthly only. */
    public function monthlyOnly(): static
    {
        return $this->state(fn (array $attributes) => ['yearly_price' => null]);
    }

    /**
     * Gives the plan exactly these features (plain on/off ones; AI gets no
     * limit unless added with {@see withAiCredits()} / {@see withUnlimitedAi()}).
     */
    public function withFeatures(PlanFeature ...$features): static
    {
        return $this->afterCreating(function (Plan $plan) use ($features): void {
            foreach ($features as $feature) {
                PlanFeatureLimit::query()->updateOrCreate(
                    ['plan_id' => $plan->getKey(), 'feature' => $feature],
                    ['is_unlimited' => false, 'limit' => null],
                );
            }

            $plan->unsetRelation('featureLimits');
        });
    }

    public function withAiCredits(int $credits): static
    {
        return $this->afterCreating(function (Plan $plan) use ($credits): void {
            PlanFeatureLimit::query()->updateOrCreate(
                ['plan_id' => $plan->getKey(), 'feature' => PlanFeature::Ai],
                ['is_unlimited' => false, 'limit' => $credits],
            );

            $plan->unsetRelation('featureLimits');
        });
    }

    public function withUnlimitedAi(): static
    {
        return $this->afterCreating(function (Plan $plan): void {
            PlanFeatureLimit::query()->updateOrCreate(
                ['plan_id' => $plan->getKey(), 'feature' => PlanFeature::Ai],
                ['is_unlimited' => true, 'limit' => null],
            );

            $plan->unsetRelation('featureLimits');
        });
    }

    /** Library + Question Bank. */
    public function basic(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => 'basic',
            'name' => ['en' => 'Basic', 'ar' => 'الأساسية'],
            'monthly_price' => 5.99,
            'yearly_price' => 59.99,
            'sort_order' => 1,
        ])->withFeatures(PlanFeature::Library, PlanFeature::QuestionBank);
    }

    /** Library + Question Bank + Case Report + AI with limited credits. */
    public function premium(int $aiCredits = 100): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => 'premium',
            'name' => ['en' => 'Premium', 'ar' => 'المميزة'],
            'monthly_price' => 9.99,
            'yearly_price' => 99.99,
            'sort_order' => 2,
        ])
            ->withFeatures(PlanFeature::Library, PlanFeature::QuestionBank, PlanFeature::CaseReport)
            ->withAiCredits($aiCredits);
    }

    /** Everything, with unlimited AI. */
    public function ultra(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => 'ultra',
            'name' => ['en' => 'Ultra', 'ar' => 'ألترا'],
            'monthly_price' => 25.99,
            'yearly_price' => 259.99,
            'sort_order' => 3,
        ])
            ->withFeatures(PlanFeature::Library, PlanFeature::QuestionBank, PlanFeature::CaseReport)
            ->withUnlimitedAi();
    }

    /**
     * The shared Ultra plan, created once. For tests that only need "a student
     * with everything" and several students must not collide on the slug.
     */
    public static function ultraPlan(): Plan
    {
        return Plan::query()->where('slug', 'ultra')->first() ?? Plan::factory()->ultra()->create();
    }
}
