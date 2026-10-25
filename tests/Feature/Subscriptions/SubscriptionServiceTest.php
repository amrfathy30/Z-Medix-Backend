<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionRequestStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscription\SubscriptionException;
use App\Models\Plan;
use App\Models\StudentSubscription;
use App\Models\SubscriptionPeriod;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Services\Subscription\RecordedPayment;
use App\Services\Subscription\SubscriptionService;
use Database\Factories\SubscriptionPeriodFactory;
use Illuminate\Support\Facades\Storage;

/**
 * Turning a payment into access: the first activation, renewals, upgrades and
 * the dates they produce, the screenshot, and what happens to the request.
 */
class SubscriptionServiceTest extends SubscriptionTestCase
{
    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SubscriptionService::class);
    }

    private function pay(
        User $student,
        Plan $plan,
        BillingCycle $cycle = BillingCycle::Monthly,
        ?SubscriptionRequest $request = null,
        string|float $amount = 9.99,
    ): SubscriptionPeriod {
        return $this->service->recordPeriod(new RecordedPayment(
            student: $student,
            plan: $plan,
            cycle: $cycle,
            amount: $amount,
            proof: SubscriptionPeriodFactory::fakeProof(),
            request: $request,
        ));
    }

    // ─── First activation ───────────────────────────────────────────────────

    public function test_the_first_payment_creates_the_subscription_and_activates_it(): void
    {
        $this->travelTo('2026-03-10 12:00:00');
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->premium()->create();

        $period = $this->pay($student, $plan, amount: 9.99);

        $subscription = StudentSubscription::query()->where('user_id', $student->id)->sole();

        $this->assertTrue($period->subscription->is($subscription));
        $this->assertSame('2026-03-10 12:00:00', $period->starts_at->toDateTimeString());
        $this->assertSame('2026-04-10 12:00:00', $period->ends_at->toDateTimeString());
        $this->assertSame('9.99', $period->amount);
        $this->assertSame('USD', $period->currency);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status());
        $this->assertTrue($subscription->currentPeriod()->plan->is($plan));
    }

    public function test_a_yearly_period_lasts_a_year(): void
    {
        $this->travelTo('2026-03-10 12:00:00');

        $period = $this->pay($this->unsubscribedStudent(), Plan::factory()->basic()->create(), BillingCycle::Yearly);

        $this->assertSame('2027-03-10 12:00:00', $period->ends_at->toDateTimeString());
        $this->assertSame(BillingCycle::Yearly, $period->billing_cycle);
    }

    public function test_a_period_started_at_the_end_of_a_month_does_not_overflow(): void
    {
        $this->travelTo('2026-01-31 09:00:00');

        $period = $this->pay($this->unsubscribedStudent(), Plan::factory()->basic()->create());

        $this->assertSame('2026-02-28 09:00:00', $period->ends_at->toDateTimeString());
    }

    public function test_the_payment_screenshot_is_kept_privately_on_the_period(): void
    {
        $period = $this->pay($this->unsubscribedStudent(), Plan::factory()->basic()->create());

        $proof = $period->fresh()->proof();

        $this->assertNotNull($proof);
        $this->assertSame('local', $proof->disk);
        $this->assertSame(SubscriptionPeriod::PROOF_COLLECTION, $proof->collection_name);
        Storage::disk('local')->assertExists($proof->getPathRelativeToRoot());
        Storage::disk('public')->assertDirectoryEmpty('/');
        Storage::disk('filament_public')->assertDirectoryEmpty('/');
    }

    public function test_the_period_snapshots_the_amount_the_plan_and_the_cycle_that_were_paid(): void
    {
        $plan = Plan::factory()->basic()->create();
        $period = $this->pay($this->unsubscribedStudent(), $plan, BillingCycle::Yearly, amount: 49.50);

        // The admin later reprices and renames the plan: the invoice does not move.
        $plan->update(['yearly_price' => 999, 'monthly_price' => 99]);

        $period->refresh();
        $this->assertSame('49.50', $period->amount);
        $this->assertSame(BillingCycle::Yearly, $period->billing_cycle);
        $this->assertSame($plan->id, $period->plan_id);
    }

    public function test_the_currency_defaults_to_the_plans_and_can_be_overridden(): void
    {
        $plan = Plan::factory()->basic()->create(['currency' => 'EGP']);

        $this->assertSame('EGP', $this->pay($this->unsubscribedStudent(), $plan)->currency);

        $override = $this->service->recordPeriod(new RecordedPayment(
            student: $this->unsubscribedStudent(),
            plan: $plan,
            cycle: BillingCycle::Monthly,
            amount: 5,
            proof: SubscriptionPeriodFactory::fakeProof(),
            currency: 'USD',
        ));

        $this->assertSame('USD', $override->currency);
    }

    // ─── Renewals and upgrades ──────────────────────────────────────────────

    public function test_a_renewal_starts_immediately_even_with_remaining_coverage(): void
    {
        $this->travelTo('2026-03-10 12:00:00');
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->basic()->create();

        $first = $this->pay($student, $plan);
        $this->travelTo('2026-03-25 12:00:00');
        $second = $this->pay($student, $plan);

        $this->assertTrue($second->starts_at->equalTo(now()));
        $this->assertSame('2026-04-25 12:00:00', $second->ends_at->toDateTimeString());
        $this->assertSame(1, StudentSubscription::query()->count(), 'Renewals add periods, not subscriptions.');
        $this->assertSame(2, $first->subscription->periods()->count());
    }

    public function test_a_renewal_after_the_subscription_lapsed_starts_now(): void
    {
        $this->travelTo('2026-03-10 12:00:00');
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->basic()->create();

        $this->pay($student, $plan);
        $this->travelTo('2026-06-01 08:00:00');

        $renewal = $this->pay($student, $plan);

        $this->assertSame('2026-06-01 08:00:00', $renewal->starts_at->toDateTimeString());
        $this->assertSame(SubscriptionStatus::Active, $renewal->subscription->status());
    }

    public function test_an_upgrade_begins_immediately(): void
    {
        $this->travelTo('2026-03-10 12:00:00');
        $student = $this->unsubscribedStudent();
        $basic = Plan::factory()->basic()->create();
        $premium = Plan::factory()->premium()->create();

        $this->pay($student, $basic);
        $this->travelTo('2026-03-12 12:00:00');
        $upgrade = $this->pay($student, $premium);

        $this->assertSame('2026-03-12 12:00:00', $upgrade->starts_at->toDateTimeString());
        // The upgrade started last, so it decides the plan while it runs.
        $this->assertTrue($upgrade->subscription->currentPeriod()->plan->is($premium));
    }

    public function test_a_payment_date_does_not_control_activation(): void
    {
        $this->travelTo('2026-03-10 12:34:56');
        $period = $this->service->recordPeriod(new RecordedPayment(
            student: $this->unsubscribedStudent(),
            plan: Plan::factory()->basic()->create(),
            cycle: BillingCycle::Monthly,
            amount: 10,
            proof: SubscriptionPeriodFactory::fakeProof(),
            paidAt: now()->addDays(5),
        ));

        $this->assertTrue($period->starts_at->equalTo(now()));
        $this->assertTrue($period->subscription->isActive());
    }

    // ─── Cancelling ─────────────────────────────────────────────────────────

    public function test_cancelling_withdraws_access_and_a_new_payment_restores_it(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->basic()->create();
        $subscription = $this->pay($student, $plan)->subscription;

        $this->service->cancel($subscription, 'Chargeback');

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->fresh()->status());
        $this->assertSame('Chargeback', $subscription->fresh()->cancellation_reason);

        $this->pay($student, $plan);

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status());
        $this->assertNull($subscription->fresh()->cancelled_at);
        $this->assertNull($subscription->fresh()->cancellation_reason);
    }

    public function test_reinstating_restores_the_remaining_coverage(): void
    {
        $subscription = $this->pay($this->unsubscribedStudent(), Plan::factory()->basic()->create())->subscription;

        $this->service->cancel($subscription);
        $this->service->reinstate($subscription);

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status());
    }

    // ─── Requests ───────────────────────────────────────────────────────────

    public function test_recording_a_payment_for_a_request_approves_it_and_links_the_period(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->premium()->create();
        $admin = $this->superAdmin();
        $request = SubscriptionRequest::factory()->create(['user_id' => $student->id, 'plan_id' => $plan->id]);

        $period = $this->service->recordPeriod(new RecordedPayment(
            student: $student,
            plan: $plan,
            cycle: BillingCycle::Monthly,
            amount: 9.99,
            proof: SubscriptionPeriodFactory::fakeProof(),
            recordedBy: $admin,
            request: $request,
        ));

        $request->refresh();
        $this->assertSame(SubscriptionRequestStatus::Approved, $request->status);
        $this->assertSame($admin->id, $request->handled_by);
        $this->assertNotNull($request->handled_at);
        $this->assertSame($request->id, $period->subscription_request_id);
        $this->assertSame($admin->id, $period->recorded_by);
        $this->assertTrue($request->period->is($period));
    }

    public function test_a_request_cannot_be_approved_twice(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->create(['user_id' => $student->id, 'plan_id' => $plan->id]);

        $this->pay($student, $plan, request: $request);

        try {
            $this->pay($student, $plan, request: $request);
            $this->fail('A handled request was approved again.');
        } catch (SubscriptionException $exception) {
            $this->assertSame('REQUEST_NOT_PENDING', $exception->errorCode);
        }

        $this->assertSame(1, SubscriptionPeriod::query()->count(), 'The second payment must not be recorded.');
    }

    public function test_a_rejected_request_cannot_be_approved(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->rejected()->create(['user_id' => $student->id, 'plan_id' => $plan->id]);

        $this->expectException(SubscriptionException::class);

        $this->pay($student, $plan, request: $request);
    }

    public function test_a_request_cannot_be_approved_for_another_student(): void
    {
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->create(['plan_id' => $plan->id]);

        try {
            $this->pay($this->unsubscribedStudent(), $plan, request: $request);
            $this->fail('A request was approved for someone else.');
        } catch (SubscriptionException $exception) {
            $this->assertSame('REQUEST_STUDENT_MISMATCH', $exception->errorCode);
        }

        $this->assertDatabaseCount('subscription_periods', 0);
        $this->assertDatabaseCount('student_subscriptions', 0);
    }

    public function test_rejecting_a_request_records_who_and_why_without_granting_access(): void
    {
        $student = $this->unsubscribedStudent();
        $request = SubscriptionRequest::factory()->create(['user_id' => $student->id]);
        $admin = $this->superAdmin();

        $this->service->rejectRequest($request, $admin, 'Could not reach the student');

        $request->refresh();
        $this->assertSame(SubscriptionRequestStatus::Rejected, $request->status);
        $this->assertSame('Could not reach the student', $request->admin_note);
        $this->assertSame($admin->id, $request->handled_by);
        $this->assertDatabaseCount('student_subscriptions', 0);
    }

    public function test_a_handled_request_cannot_be_rejected(): void
    {
        $request = SubscriptionRequest::factory()->rejected()->create();

        $this->expectException(SubscriptionException::class);

        $this->service->rejectRequest($request);
    }

    public function test_a_failed_screenshot_leaves_nothing_behind(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->create(['user_id' => $student->id, 'plan_id' => $plan->id]);

        try {
            // Not a file at all, so the media library refuses it.
            $this->service->recordPeriod(new RecordedPayment(
                student: $student,
                plan: $plan,
                cycle: BillingCycle::Monthly,
                amount: 5,
                proof: '/no/such/file.jpg',
                request: $request,
            ));
            $this->fail('A payment without a readable screenshot was recorded.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertDatabaseCount('subscription_periods', 0);
        $this->assertDatabaseCount('student_subscriptions', 0);
        $this->assertSame(SubscriptionRequestStatus::Pending, $request->fresh()->status);
    }
}
