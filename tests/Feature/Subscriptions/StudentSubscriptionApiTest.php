<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionRequestStatus;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\StudentSubscription;
use App\Models\SubscriptionRequest;
use Database\Factories\SubscriptionPeriodFactory;

/**
 * What a student sees and can do about plans: the pricing list, their own
 * subscription (only ever the features they actually have), and asking for a
 * plan.
 */
class StudentSubscriptionApiTest extends SubscriptionTestCase
{
    private const PLANS = '/api/student/plans';

    private const SUBSCRIPTION = '/api/student/subscription';

    private const REQUESTS = '/api/student/subscription-requests';

    // ─── Plans ──────────────────────────────────────────────────────────────

    public function test_plans_list_active_plans_in_order_with_both_prices_and_their_features(): void
    {
        Plan::factory()->ultra()->create();
        Plan::factory()->basic()->create();
        Plan::factory()->premium(300)->create();
        Plan::factory()->inactive()->create(['slug' => 'hidden', 'sort_order' => 0]);

        $this->actingAsStudent($this->unsubscribedStudent());

        $response = $this->getJson(self::PLANS)->assertOk();

        $this->assertSame(['basic', 'premium', 'ultra'], collect($response->json('data'))->pluck('slug')->all());

        $basic = $response->json('data.0');
        $this->assertSame(['monthly' => 5.99, 'yearly' => 59.99], $basic['prices']);
        $this->assertSame('USD', $basic['currency']);
        $this->assertSame(['library', 'question_bank'], collect($basic['features'])->pluck('key')->all());

        $premiumAi = collect($response->json('data.1.features'))->firstWhere('key', 'ai');
        $this->assertSame(['key' => 'ai', 'label' => 'AI Assistant', 'unlimited' => false, 'limit' => 300], $premiumAi);

        $ultraAi = collect($response->json('data.2.features'))->firstWhere('key', 'ai');
        $this->assertSame(['key' => 'ai', 'label' => 'AI Assistant', 'unlimited' => true, 'limit' => null], $ultraAi);
    }

    public function test_a_cycle_a_plan_does_not_sell_has_a_null_price(): void
    {
        Plan::factory()->basic()->monthlyOnly()->create();

        $this->actingAsStudent($this->unsubscribedStudent());

        $this->getJson(self::PLANS)
            ->assertJsonPath('data.0.prices.monthly', 5.99)
            ->assertJsonPath('data.0.prices.yearly', null);
    }

    public function test_plans_mark_the_one_the_student_is_on(): void
    {
        $basic = Plan::factory()->basic()->create();
        Plan::factory()->premium()->create();

        $this->actingAsStudent($this->subscribedStudent($basic));

        $response = $this->getJson(self::PLANS)->assertOk();

        $this->assertSame(
            ['basic' => true, 'premium' => false],
            collect($response->json('data'))->pluck('is_current', 'slug')->all(),
        );
    }

    public function test_plans_are_returned_in_the_requested_language(): void
    {
        Plan::factory()->basic()->create();
        $this->actingAsStudent($this->unsubscribedStudent());

        $this->getJson(self::PLANS.'?lang=ar')
            ->assertJsonPath('data.0.name', 'الأساسية')
            ->assertJsonPath('data.0.features.0.label', 'المكتبة');
    }

    public function test_plans_require_authentication(): void
    {
        $this->getJson(self::PLANS)->assertUnauthorized();
    }

    // ─── My subscription ────────────────────────────────────────────────────

    public function test_a_student_without_a_subscription_sees_no_plan_and_no_features(): void
    {
        $this->actingAsStudent($this->unsubscribedStudent());

        $this->getJson(self::SUBSCRIPTION)
            ->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.features', [])
            ->assertJsonPath('data.ends_at', null)
            ->assertJsonPath('data.pending_request', null);
    }

    public function test_a_subscribed_student_sees_only_the_features_of_their_own_plan(): void
    {
        $this->actingAsStudent($this->subscribedStudent(Plan::factory()->basic()->create()));

        $response = $this->getJson(self::SUBSCRIPTION)
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.plan.slug', 'basic')
            ->assertJsonPath('data.billing_cycle', 'monthly');

        $this->assertSame(['library', 'question_bank'], collect($response->json('data.features'))->pluck('key')->all());
    }

    public function test_the_subscription_reports_when_it_ends_and_how_long_is_left(): void
    {
        $student = $this->unsubscribedStudent();
        $subscription = StudentSubscription::factory()->for($student, 'user')->create();
        SubscriptionPeriodFactory::new()->create([
            'student_subscription_id' => $subscription->id,
            'plan_id' => Plan::factory()->basic()->create()->id,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(10)->addHour(),
        ]);

        $this->actingAsStudent($student);

        $response = $this->getJson(self::SUBSCRIPTION)->assertOk();

        $this->assertSame(11, $response->json('data.days_remaining'));
        $this->assertNotNull($response->json('data.starts_at'));
        $this->assertNotNull($response->json('data.ends_at'));
    }

    public function test_an_expired_subscription_reports_expired_and_no_features(): void
    {
        $student = $this->unsubscribedStudent();
        StudentSubscription::factory()->for($student, 'user')->expired(Plan::factory()->basic()->create())->create();

        $this->actingAsStudent($student);

        $this->getJson(self::SUBSCRIPTION)
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.features', []);
    }

    public function test_a_cancelled_subscription_reports_cancelled_and_no_features(): void
    {
        $student = $this->unsubscribedStudent();
        StudentSubscription::factory()->for($student, 'user')->cancelled()->active(Plan::factory()->basic()->create())->create();

        $this->actingAsStudent($student);

        $this->getJson(self::SUBSCRIPTION)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.features', []);
    }

    public function test_a_pending_request_is_shown_with_the_subscription(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->premium()->create();
        SubscriptionRequest::factory()->yearly()->create(['user_id' => $student->id, 'plan_id' => $plan->id]);

        $this->actingAsStudent($student);

        $this->getJson(self::SUBSCRIPTION)
            ->assertJsonPath('data.pending_request.status', 'pending')
            ->assertJsonPath('data.pending_request.billing_cycle', 'yearly')
            ->assertJsonPath('data.pending_request.plan.slug', 'premium');
    }

    public function test_a_handled_request_is_no_longer_pending(): void
    {
        $student = $this->unsubscribedStudent();
        SubscriptionRequest::factory()->rejected('No answer')->create(['user_id' => $student->id]);

        $this->actingAsStudent($student);

        $this->getJson(self::SUBSCRIPTION)->assertJsonPath('data.pending_request', null);
    }

    public function test_the_subscription_carries_the_whatsapp_contact(): void
    {
        Setting::query()->where('key', 'whatsapp_number')->update(['value' => '+20 100 123 4567']);

        $this->actingAsStudent($this->unsubscribedStudent());

        $this->getJson(self::SUBSCRIPTION)
            ->assertJsonPath('data.contact.whatsapp_number', '+20 100 123 4567')
            ->assertJsonPath('data.contact.whatsapp_url', 'https://wa.me/201001234567');
    }

    public function test_contact_is_null_when_no_number_is_configured(): void
    {
        $this->actingAsStudent($this->unsubscribedStudent());

        $this->getJson(self::SUBSCRIPTION)
            ->assertJsonPath('data.contact.whatsapp_number', null)
            ->assertJsonPath('data.contact.whatsapp_url', null);
    }

    public function test_the_subscription_requires_authentication(): void
    {
        $this->getJson(self::SUBSCRIPTION)->assertUnauthorized();
    }

    // ─── Asking for a plan ──────────────────────────────────────────────────

    public function test_a_student_requests_a_plan(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->premium()->create();
        $this->actingAsStudent($student);

        $this->postJson(self::REQUESTS, ['plan_id' => $plan->id, 'billing_cycle' => 'yearly'])
            ->assertCreated()
            ->assertJsonPath('data.request.status', 'pending')
            ->assertJsonPath('data.request.billing_cycle', 'yearly')
            ->assertJsonPath('data.request.plan.slug', 'premium');

        $this->assertDatabaseHas('subscription_requests', [
            'user_id' => $student->id,
            'plan_id' => $plan->id,
            'billing_cycle' => 'yearly',
            'status' => 'pending',
        ]);
    }

    public function test_the_response_opens_whatsapp_with_the_request_already_typed(): void
    {
        Setting::query()->where('key', 'whatsapp_number')->update(['value' => '201001234567']);
        $plan = Plan::factory()->premium()->create();
        $this->actingAsStudent($this->unsubscribedStudent());

        $url = $this->postJson(self::REQUESTS, ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertCreated()
            ->json('data.contact.whatsapp_url');

        $this->assertStringStartsWith('https://wa.me/201001234567?text=', $url);
        $this->assertStringContainsString('Premium', rawurldecode($url));
        $this->assertStringContainsString('Monthly', rawurldecode($url));
    }

    public function test_asking_again_changes_the_pending_request_instead_of_adding_one(): void
    {
        $student = $this->unsubscribedStudent();
        $basic = Plan::factory()->basic()->create();
        $ultra = Plan::factory()->ultra()->create();
        $this->actingAsStudent($student);

        $first = $this->postJson(self::REQUESTS, ['plan_id' => $basic->id, 'billing_cycle' => 'monthly'])
            ->assertCreated()
            ->json('data.request.id');

        $this->postJson(self::REQUESTS, ['plan_id' => $ultra->id, 'billing_cycle' => 'yearly'])
            ->assertOk()
            ->assertJsonPath('data.request.id', $first)
            ->assertJsonPath('data.request.plan.slug', 'ultra')
            ->assertJsonPath('data.request.billing_cycle', 'yearly');

        $this->assertSame(1, SubscriptionRequest::query()->where('user_id', $student->id)->count());
    }

    public function test_a_new_request_can_be_made_once_the_previous_one_was_handled(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->basic()->create();
        SubscriptionRequest::factory()->rejected()->create(['user_id' => $student->id, 'plan_id' => $plan->id]);
        $this->actingAsStudent($student);

        $this->postJson(self::REQUESTS, ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])->assertCreated();

        $this->assertSame(2, SubscriptionRequest::query()->where('user_id', $student->id)->count());
        $this->assertSame(1, SubscriptionRequest::query()->pending()->count());
    }

    public function test_a_subscribed_student_can_request_a_renewal_or_upgrade(): void
    {
        $student = $this->subscribedStudent(Plan::factory()->basic()->create());
        $ultra = Plan::factory()->ultra()->create();
        $this->actingAsStudent($student);

        $this->postJson(self::REQUESTS, ['plan_id' => $ultra->id, 'billing_cycle' => 'monthly'])->assertCreated();
    }

    public function test_a_request_needs_a_real_plan_and_a_valid_cycle(): void
    {
        $this->actingAsStudent($this->unsubscribedStudent());

        $this->postJson(self::REQUESTS, [])->assertUnprocessable()->assertJsonValidationErrors(['plan_id', 'billing_cycle']);
        $this->postJson(self::REQUESTS, ['plan_id' => 9999, 'billing_cycle' => 'monthly'])->assertJsonValidationErrors('plan_id');

        $plan = Plan::factory()->basic()->create();
        $this->postJson(self::REQUESTS, ['plan_id' => $plan->id, 'billing_cycle' => 'weekly'])->assertJsonValidationErrors('billing_cycle');
    }

    public function test_a_plan_cannot_be_requested_on_a_cycle_it_does_not_sell(): void
    {
        $plan = Plan::factory()->basic()->monthlyOnly()->create();
        $this->actingAsStudent($this->unsubscribedStudent());

        $this->postJson(self::REQUESTS, ['plan_id' => $plan->id, 'billing_cycle' => 'yearly'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'BILLING_CYCLE_NOT_OFFERED');

        $this->assertDatabaseCount('subscription_requests', 0);
    }

    public function test_an_inactive_plan_cannot_be_requested(): void
    {
        $plan = Plan::factory()->basic()->inactive()->create();
        $this->actingAsStudent($this->unsubscribedStudent());

        $this->postJson(self::REQUESTS, ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PLAN_UNAVAILABLE');
    }

    public function test_a_deleted_plan_cannot_be_requested(): void
    {
        $plan = Plan::factory()->basic()->create();
        $plan->delete();
        $this->actingAsStudent($this->unsubscribedStudent());

        $this->postJson(self::REQUESTS, ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');
    }

    public function test_requesting_a_plan_requires_authentication(): void
    {
        $plan = Plan::factory()->basic()->create();

        $this->postJson(self::REQUESTS, ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])->assertUnauthorized();
    }

    public function test_a_request_never_touches_access(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->ultra()->create();
        $this->actingAsStudent($student);

        $this->postJson(self::REQUESTS, ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])->assertCreated();

        // Asking is not paying: nothing is unlocked until the admin records it.
        $this->getJson('/api/student/study/subjects')->assertForbidden();
        $this->assertSame(SubscriptionRequestStatus::Pending, SubscriptionRequest::query()->first()->status);
        $this->assertSame(BillingCycle::Monthly, SubscriptionRequest::query()->first()->billing_cycle);
        $this->assertDatabaseCount('student_subscriptions', 0);
    }
}
