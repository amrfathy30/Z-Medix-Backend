<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\BillingCycle;
use App\Enums\PlanFeature;
use App\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Models\Plan;
use Database\Factories\SubscriptionPeriodFactory;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * The admin builds plans from the dashboard: features, the AI allowance and a
 * price per billing cycle.
 */
class PlanAdminTest extends SubscriptionTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPlan(array $overrides = []): array
    {
        return array_replace([
            'slug' => 'gold',
            'name' => ['en' => 'Gold', 'ar' => 'ذهبية'],
            'monthly_price' => 12.5,
            'yearly_price' => 120,
            'currency' => 'usd',
            'is_active' => true,
            'sort_order' => 4,
            'featureLimits' => [
                ['feature' => 'library'],
                ['feature' => 'question_bank'],
                ['feature' => 'ai', 'is_unlimited' => false, 'limit' => 50],
            ],
        ], $overrides);
    }

    public function test_an_admin_creates_a_plan_with_features_and_an_ai_allowance(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreatePlan::class)
            ->fillForm($this->validPlan())
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Plan::query()->where('slug', 'gold')->with('featureLimits')->sole();

        $this->assertSame('Gold', $plan->getTranslation('name', 'en'));
        $this->assertSame('ذهبية', $plan->getTranslation('name', 'ar'));
        $this->assertSame('12.50', $plan->monthly_price);
        $this->assertSame('120.00', $plan->yearly_price);
        $this->assertSame('USD', $plan->currency);
        $this->assertSame(
            ['library', 'question_bank', 'ai'],
            $plan->orderedFeatureLimits()->map(fn ($limit) => $limit->feature->value)->all(),
        );
        $this->assertSame(50, $plan->limitFor(PlanFeature::Ai)->limit);
        $this->assertFalse($plan->limitFor(PlanFeature::Ai)->is_unlimited);
    }

    public function test_a_plan_can_be_unlimited_on_ai(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreatePlan::class)
            ->fillForm($this->validPlan([
                'featureLimits' => [
                    ['feature' => 'library'],
                    ['feature' => 'ai', 'is_unlimited' => true],
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $ai = Plan::query()->where('slug', 'gold')->sole()->load('featureLimits')->limitFor(PlanFeature::Ai);

        $this->assertTrue($ai->is_unlimited);
        $this->assertNull($ai->limit);
    }

    public function test_ai_needs_either_unlimited_or_a_number_of_credits(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreatePlan::class)
            ->fillForm($this->validPlan([
                'featureLimits' => [['feature' => 'ai', 'is_unlimited' => false]],
            ]))
            ->call('create')
            ->assertHasFormErrors();

        $this->assertDatabaseCount('plans', 0);
    }

    public function test_a_plan_needs_at_least_one_feature(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreatePlan::class)
            ->fillForm($this->validPlan(['featureLimits' => []]))
            ->call('create')
            ->assertHasFormErrors();
    }

    public function test_a_plan_needs_at_least_one_price(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreatePlan::class)
            ->fillForm($this->validPlan(['monthly_price' => null, 'yearly_price' => null]))
            ->call('create')
            ->assertHasFormErrors(['monthly_price', 'yearly_price']);
    }

    public function test_a_plan_can_be_sold_on_one_cycle_only(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreatePlan::class)
            ->fillForm($this->validPlan(['yearly_price' => null]))
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Plan::query()->where('slug', 'gold')->sole();
        $this->assertNull($plan->yearly_price);
        $this->assertFalse($plan->offers(BillingCycle::Yearly));
    }

    public function test_the_same_feature_cannot_be_added_twice(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreatePlan::class)
            ->fillForm($this->validPlan([
                'featureLimits' => [['feature' => 'library'], ['feature' => 'library']],
            ]))
            ->call('create')
            ->assertHasFormErrors();
    }

    public function test_the_slug_must_be_unique_and_url_safe(): void
    {
        Plan::factory()->create(['slug' => 'gold']);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreatePlan::class)
            ->fillForm($this->validPlan())
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreatePlan::class)
            ->fillForm($this->validPlan(['slug' => 'not a slug!']))
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_prices_cannot_be_negative(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(CreatePlan::class)
            ->fillForm($this->validPlan(['monthly_price' => -1]))
            ->call('create')
            ->assertHasFormErrors(['monthly_price']);
    }

    public function test_editing_a_plan_updates_its_features(): void
    {
        $plan = Plan::factory()->basic()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm([
                'monthly_price' => 7.5,
                'featureLimits' => [
                    ['feature' => 'library'],
                    ['feature' => 'case_report'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $plan->refresh()->load('featureLimits');

        $this->assertSame('7.50', $plan->monthly_price);
        $this->assertTrue($plan->hasFeature(PlanFeature::CaseReport));
        $this->assertFalse($plan->hasFeature(PlanFeature::QuestionBank));
    }

    public function test_removing_ai_from_a_plan_removes_the_feature_and_its_allowance(): void
    {
        $plan = Plan::factory()->withFeatures(PlanFeature::Library)->withAiCredits(75)->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm([
                'featureLimits' => [
                    ['feature' => 'library'],
                    ['feature' => 'case_report'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $plan->refresh()->load('featureLimits');
        $this->assertFalse($plan->hasFeature(PlanFeature::Ai));
        $this->assertDatabaseMissing('plan_feature_limits', ['plan_id' => $plan->id, 'feature' => 'ai']);
    }

    public function test_marking_ai_unlimited_clears_any_credit_number(): void
    {
        $plan = Plan::factory()->withFeatures(PlanFeature::Library)->withAiCredits(75)->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->fillForm([
                'featureLimits' => [
                    ['feature' => 'library'],
                    ['feature' => 'ai', 'is_unlimited' => true, 'limit' => 75],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $ai = $plan->refresh()->load('featureLimits')->limitFor(PlanFeature::Ai);
        $this->assertTrue($ai->is_unlimited);
        $this->assertNull($ai->limit, 'An unlimited allowance must not keep a stale number.');
    }

    public function test_the_slug_is_fixed_once_the_plan_exists(): void
    {
        $plan = Plan::factory()->basic()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->assertFormFieldIsDisabled('slug');
    }

    public function test_deleting_a_plan_keeps_it_for_the_history_that_used_it(): void
    {
        $plan = Plan::factory()->basic()->create();
        $period = SubscriptionPeriodFactory::new()->current()->create(['plan_id' => $plan->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListPlans::class)
            ->callAction(TestAction::make('delete')->table($plan));

        $this->assertSoftDeleted('plans', ['id' => $plan->id]);
        $this->assertSame($plan->id, $period->fresh()->plan->id, 'A paid period still resolves its deleted plan.');
    }

    public function test_a_deleted_plan_can_be_restored(): void
    {
        $plan = Plan::factory()->basic()->create();
        $plan->delete();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListPlans::class)
            ->filterTable('trashed', true)
            ->callAction(TestAction::make('restore')->table($plan));

        $this->assertNotSoftDeleted('plans', ['id' => $plan->id]);
    }

    public function test_the_list_shows_each_plans_features(): void
    {
        $basic = Plan::factory()->basic()->create();
        $ultra = Plan::factory()->ultra()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListPlans::class)
            ->assertCanSeeTableRecords([$basic, $ultra])
            ->assertSee('Case Report');
    }

    // ─── Who may manage plans ───────────────────────────────────────────────

    public function test_the_admin_role_can_read_plans_but_not_change_them(): void
    {
        $admin = $this->adminWithRole('admin');
        $plan = Plan::factory()->basic()->create();

        Livewire::actingAs($admin, 'admin')->test(ListPlans::class)->assertSuccessful();
        Livewire::actingAs($admin, 'admin')->test(CreatePlan::class)->assertForbidden();
        Livewire::actingAs($admin, 'admin')->test(EditPlan::class, ['record' => $plan->getRouteKey()])->assertForbidden();
    }

    public function test_a_content_manager_cannot_open_plans(): void
    {
        Livewire::actingAs($this->adminWithRole('content_manager'), 'admin')
            ->test(ListPlans::class)
            ->assertForbidden();
    }
}
