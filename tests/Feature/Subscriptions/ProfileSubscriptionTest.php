<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\PlanFeature;
use App\Models\Plan;
use App\Models\StudentSubscription;
use App\Models\SubscriptionPeriod;
use App\Models\SubscriptionRequest;
use PHPUnit\Framework\Attributes\DataProvider;

class ProfileSubscriptionTest extends SubscriptionTestCase
{
    public function test_profile_reuses_the_current_subscription_contract_and_own_entitlements(): void
    {
        $this->freezeTime();
        $plan = Plan::factory()->withFeatures(PlanFeature::Library)->withAiCredits(125)->create();
        $student = $this->subscribedStudent($plan);
        $this->subscribedStudent(Plan::factory()->ultra()->create());
        $this->actingAsStudent($student);

        $subscription = $this->getJson('/api/student/subscription')->assertOk()->json('data');
        $profile = $this->getJson('/api/public/profile')->assertOk()
            ->assertJsonPath('data.id', $student->id)
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.plan.id', $plan->id)
            ->assertJsonPath('data.subscription.plan.name', $plan->name)
            ->assertJsonPath('data.subscription.plan.slug', $plan->slug)
            ->json('data.subscription');

        $this->assertSame($subscription, $profile);
        $this->assertSame(['library', 'ai'], array_column($profile['features'], 'key'));
        $this->assertSame(['key' => 'ai', 'label' => 'AI Assistant', 'unlimited' => false, 'limit' => 125], $profile['features'][1]);
        $period = $student->studentSubscription->currentPeriod();
        $this->assertSame($period->starts_at->toIso8601String(), $profile['starts_at']);
        $this->assertSame($period->ends_at->toIso8601String(), $profile['ends_at']);
    }

    public function test_profile_reports_unlimited_ai_using_the_existing_feature_shape(): void
    {
        $this->actingAsStudent($this->subscribedStudent(Plan::factory()->ultra()->create()));

        $this->getJson('/api/public/profile')->assertOk()
            ->assertJsonPath('data.subscription.features.3.key', 'ai')
            ->assertJsonPath('data.subscription.features.3.unlimited', true)
            ->assertJsonPath('data.subscription.features.3.limit', null);
    }

    #[DataProvider('unavailableStates')]
    public function test_profile_grants_nothing_without_a_current_entitlement(string $state, string $status): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->premium()->create();

        if ($state === 'expired') {
            StudentSubscription::factory()->for($student, 'user')->expired($plan)->create();
        } elseif ($state === 'cancelled') {
            StudentSubscription::factory()->for($student, 'user')->cancelled()->active($plan)->create();
        } elseif ($state === 'future') {
            $subscription = StudentSubscription::factory()->for($student, 'user')->create();
            SubscriptionPeriod::factory()->upcoming()->for($subscription, 'subscription')->for($plan)->create();
        } elseif ($state === 'pending') {
            SubscriptionRequest::factory()->for($student, 'user')->for($plan)->create();
        }

        $this->actingAsStudent($student);
        $this->getJson('/api/public/profile')->assertOk()
            ->assertJsonPath('data.subscription.status', $status)
            ->assertJsonPath('data.subscription.plan', null)
            ->assertJsonPath('data.subscription.features', [])
            ->assertJsonPath('data.subscription.starts_at', null)
            ->assertJsonPath('data.subscription.ends_at', null);
    }

    public static function unavailableStates(): array
    {
        return [
            'none' => ['none', 'none'],
            'expired' => ['expired', 'expired'],
            'cancelled' => ['cancelled', 'cancelled'],
            'future' => ['future', 'expired'],
            'pending' => ['pending', 'none'],
        ];
    }
}
