<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\BillingCycle;
use App\Enums\PlanFeature;
use App\Enums\PromoCodeUsageStatus;
use App\Enums\SubscriptionRequestStatus;
use App\Enums\SubscriptionStatus;
use App\Events\SubscriptionApproved;
use App\Exceptions\Subscription\SubscriptionException;
use App\Filament\Resources\StudentSubscriptionResource\Pages\ListStudentSubscriptions;
use App\Filament\Resources\StudentSubscriptionResource\Pages\ViewStudentSubscription;
use App\Filament\Resources\StudentSubscriptionResource\RelationManagers\PeriodsRelationManager;
use App\Filament\Resources\SubscriptionRequestResource\Pages\ListSubscriptionRequests;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\StudentSubscription;
use App\Models\Subject;
use App\Models\SubscriptionPeriod;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Services\Subscription\FeatureAccessService;
use App\Services\Subscription\RecordedPayment;
use App\Services\Subscription\SubscriptionService;
use Database\Factories\SubscriptionPeriodFactory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;

class SubscriptionLifecycleTest extends SubscriptionTestCase
{
    private function pay(User $student, Plan $plan, BillingCycle $cycle = BillingCycle::Monthly, ?SubscriptionRequest $request = null): SubscriptionPeriod
    {
        return app(SubscriptionService::class)->recordPeriod(new RecordedPayment(
            student: $student, plan: $plan, cycle: $cycle, amount: 10,
            proof: SubscriptionPeriodFactory::fakeProof(), request: $request,
        ));
    }

    public function test_pending_request_grants_nothing_and_approval_uses_the_server_timestamp(): void
    {
        $this->travelTo('2026-03-10 12:34:56');
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->premium()->create();
        $this->actingAsStudent($student);
        $requestId = $this->postJson('/api/student/subscription-requests', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
            'starts_at' => now()->addDays(5)->toDateTimeString(),
        ])->assertCreated()->assertJsonPath('data.request.status', 'pending')->json('data.request.id');
        $request = SubscriptionRequest::findOrFail($requestId);
        $snapshot = $request->pricing_snapshot;
        $this->assertDatabaseCount('subscription_periods', 0);
        $this->assertNoEntitlement();
        foreach (['/api/student/subscription' => 'data', '/api/public/profile' => 'data.subscription'] as $url => $key) {
            $this->getJson($url)->assertOk()->assertJsonPath($key.'.status', 'none')
                ->assertJsonPath($key.'.plan', null)->assertJsonPath($key.'.features', []);
        }

        $this->travelTo('2026-03-12 15:16:17');
        $period = $this->pay($student, $plan, request: $request);
        $this->assertSame('2026-03-12 15:16:17', $period->starts_at->toDateTimeString());
        $this->assertTrue($request->fresh()->handled_at->equalTo($period->starts_at));
        $this->assertSame('2026-04-12 15:16:17', $period->ends_at->toDateTimeString());
        $this->assertSame($snapshot, $request->fresh()->pricing_snapshot);
        $this->assertSame($period->id, app(FeatureAccessService::class)->currentPeriod($student)->id);
        foreach (['/api/student/subscription' => 'data', '/api/public/profile' => 'data.subscription'] as $url => $key) {
            $this->getJson($url)->assertOk()->assertJsonPath($key.'.status', 'active')
                ->assertJsonPath($key.'.plan.id', $plan->id)
                ->assertJsonPath($key.'.starts_at', $period->starts_at->toIso8601String());
        }
        $this->getJson('/api/student/subscription-requests')->assertOk()->assertJsonPath('data.0.status', 'approved');
        $this->travelTo($period->ends_at);
        $this->assertSame(SubscriptionStatus::Expired, $period->subscription->status());
        $this->assertNoEntitlement();
    }

    public function test_upgrade_permanently_supersedes_old_coverage_without_rewriting_history(): void
    {
        $this->travelTo('2026-03-10 12:00:00');
        $student = $this->unsubscribedStudent();
        $oldPlan = Plan::factory()->withFeatures(PlanFeature::CaseReport)->create();
        $newPlan = Plan::factory()->withFeatures(PlanFeature::Library)->create();
        $old = $this->pay($student, $oldPlan, BillingCycle::Yearly);
        $oldAttributes = $old->fresh()->getRawOriginal();
        $this->travelTo('2026-03-12 12:00:00');
        $request = app(SubscriptionService::class)->submitRequest($student, $newPlan, BillingCycle::Monthly);
        Event::fake([SubscriptionApproved::class]);
        $new = $this->pay($student, $newPlan, request: $request);
        Event::assertDispatchedTimes(SubscriptionApproved::class, 1);
        $access = app(FeatureAccessService::class);
        $this->assertSame($new->id, $access->currentPeriod($student)->id);
        $this->assertTrue($access->hasFeature($student, PlanFeature::Library));
        $this->assertFalse($access->hasFeature($student, PlanFeature::CaseReport));
        $this->actingAsStudent($student);
        $subject = Subject::factory()->create();
        $this->getJson('/api/public/profile')->assertOk()->assertJsonPath('data.subscription.plan.id', $newPlan->id)
            ->assertJsonPath('data.subscription.features.0.key', 'library');
        $this->getJson('/api/student/subscription')->assertOk()->assertJsonPath('data.plan.id', $newPlan->id);
        $this->getJson('/api/student/dashboard')->assertOk()->assertJsonPath('data.subjects.0.id', $subject->id)
            ->assertJsonPath('data.code.subjects', null)->assertJsonPath('data.report_cases', [])
            ->assertJsonPath('data.code.report_cases', 'FEATURE_NOT_IN_PLAN');
        $this->getJson('/api/student/report-cases')->assertOk()->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');

        $this->travelTo($new->ends_at);
        $this->assertTrue($old->ends_at->isFuture());
        $this->assertNull($access->currentPeriod($student));
        $this->assertSame(SubscriptionStatus::Expired, $access->statusFor($student));
        $this->assertTrue(StudentSubscription::query()->expired()->whereKey($new->subscription)->exists());
        $this->assertFalse(StudentSubscription::query()->active()->whereKey($new->subscription)->exists());
        $this->getJson('/api/public/profile')->assertOk()->assertJsonPath('data.subscription.status', 'expired')
            ->assertJsonPath('data.subscription.plan', null)->assertJsonPath('data.subscription.features', []);
        $this->getJson('/api/student/subscription')->assertOk()->assertJsonPath('data.status', 'expired')->assertJsonPath('data.plan', null);
        $this->assertNoEntitlement();
        $this->assertSame($oldAttributes, $old->fresh()->getRawOriginal());
        $this->assertSame(2, $new->subscription->periods()->count());
        $renewal = $this->pay($student, $newPlan);
        $this->assertTrue($renewal->starts_at->equalTo(now()), 'A superseded yearly end must not delay renewal.');
    }

    public function test_pending_upgrade_preserves_current_access_until_approval(): void
    {
        $this->travelTo('2026-03-10 12:00:00');
        $student = $this->unsubscribedStudent();
        $oldPlan = Plan::factory()->withFeatures(PlanFeature::CaseReport)->create();
        $newPlan = Plan::factory()->withFeatures(PlanFeature::Library)->create();
        $old = $this->pay($student, $oldPlan, BillingCycle::Yearly);
        $this->travelTo('2026-03-12 12:34:56');
        $this->actingAsStudent($student);
        $id = $this->postJson('/api/student/subscription-requests', [
            'plan_id' => $newPlan->id, 'billing_cycle' => 'monthly',
        ])->assertCreated()->json('data.request.id');
        $access = app(FeatureAccessService::class);
        $this->assertSame($old->id, $access->currentPeriod($student)->id);
        $this->assertTrue($access->hasFeature($student, PlanFeature::CaseReport));
        $this->assertFalse($access->hasFeature($student, PlanFeature::Library));
        foreach (['/api/student/subscription' => 'data', '/api/public/profile' => 'data.subscription'] as $url => $key) {
            $this->getJson($url)->assertOk()->assertJsonPath($key.'.plan.id', $oldPlan->id)
                ->assertJsonPath($key.'.pending_request.id', $id);
        }
        $this->getJson('/api/student/dashboard')->assertOk()->assertJsonPath('data.code.report_cases', null)
            ->assertJsonPath('data.code.subjects', 'FEATURE_NOT_IN_PLAN');
        $this->getJson('/api/student/report-cases')->assertOk()->assertJsonMissingPath('code');

        $new = $this->pay($student, $newPlan, request: SubscriptionRequest::findOrFail($id));
        $this->assertTrue($new->starts_at->equalTo(now()));
        $this->assertSame($new->id, $access->currentPeriod($student)->id);
        $this->assertFalse($access->hasFeature($student, PlanFeature::CaseReport));
        $this->assertTrue($access->hasFeature($student, PlanFeature::Library));
    }

    public function test_identical_start_times_choose_the_latest_record_without_reviving_the_previous_one(): void
    {
        $this->travelTo('2026-03-10 12:00:00');
        $student = $this->unsubscribedStudent();
        $this->pay($student, Plan::factory()->basic()->create(), BillingCycle::Yearly);
        $new = $this->pay($student, Plan::factory()->premium()->create());
        $this->assertSame($new->id, $new->subscription->currentPeriod()->id);
        $this->travelTo($new->ends_at);
        $this->assertNull($new->subscription->currentPeriod());
    }

    public function test_rejected_upgrade_preserves_current_access_and_cancellation_has_priority(): void
    {
        $this->travelTo('2026-03-10 12:00:00');
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->withFeatures(PlanFeature::CaseReport)->create();
        $old = $this->pay($student, $plan);
        $attributes = $old->fresh()->getRawOriginal();
        $service = app(SubscriptionService::class);
        $request = $service->submitRequest($student, Plan::factory()->premium()->create(), BillingCycle::Monthly);
        $service->rejectRequest($request);
        $this->assertSame($old->id, app(FeatureAccessService::class)->currentPeriod($student)->id);
        $this->assertSame($attributes, $old->fresh()->getRawOriginal());
        $this->actingAsStudent($student);
        foreach (['/api/student/subscription' => 'data', '/api/public/profile' => 'data.subscription'] as $url => $key) {
            $this->getJson($url)->assertOk()->assertJsonPath($key.'.status', 'active')
                ->assertJsonPath($key.'.plan.id', $plan->id)->assertJsonPath($key.'.pending_request', null);
        }
        $this->getJson('/api/student/dashboard')->assertOk()->assertJsonPath('data.code.report_cases', null);
        $this->getJson('/api/student/report-cases')->assertOk()->assertJsonMissingPath('code');
        $service->cancel($old->subscription);
        $this->assertSame(SubscriptionStatus::Cancelled, $old->subscription->fresh()->status());
        $this->assertNoEntitlement();
    }

    public function test_pending_updates_preserve_handled_requests_and_saved_pricing_in_student_history(): void
    {
        $this->freezeTime();
        $service = app(SubscriptionService::class);
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->create(['monthly_price' => 100, 'yearly_price' => 1000]);
        $promo = PromoCode::factory()->create(['discount_percentage' => 20]);
        $admin = $this->superAdmin();
        $rejected = $service->submitRequest($student, $plan, BillingCycle::Monthly, $promo->code);
        $service->rejectRequest($rejected, $admin, 'Payment not received');
        $rejectedAttributes = $rejected->fresh()->getRawOriginal();
        $released = $rejected->promoCodeUsages()->sole();
        $approved = $service->submitRequest($student, $plan, BillingCycle::Monthly, $promo->code);
        $this->pay($student, $plan, request: $approved);
        $approvedAttributes = $approved->fresh()->getRawOriginal();
        $plan->update(['monthly_price' => 300, 'yearly_price' => 3000]);
        $promo->update(['discount_percentage' => 50]);
        $this->actingAsStudent($student);
        $payload = ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'];
        $pendingId = $this->postJson('/api/student/subscription-requests', $payload)->assertCreated()->json('data.request.id');
        $this->postJson('/api/student/subscription-requests', $payload)->assertOk()->assertJsonPath('data.request.id', $pendingId);
        $this->postJson('/api/student/subscription-requests', array_replace($payload, ['billing_cycle' => 'yearly']))
            ->assertOk()->assertJsonPath('data.request.id', $pendingId)->assertJsonPath('data.request.pricing.final_price', 3000);
        $replacement = Plan::factory()->create(['monthly_price' => 75]);
        $this->postJson('/api/student/subscription-requests', ['plan_id' => $replacement->id, 'billing_cycle' => 'monthly'])
            ->assertOk()->assertJsonPath('data.request.id', $pendingId)->assertJsonPath('data.request.pricing.final_price', 75);
        $this->assertSame(1, $student->subscriptionRequests()->pending()->count());
        $this->assertSame($rejectedAttributes, $rejected->fresh()->getRawOriginal());
        $this->assertSame($approvedAttributes, $approved->fresh()->getRawOriginal());
        $this->assertSame(PromoCodeUsageStatus::Released, $released->fresh()->status);
        $this->assertSame(PromoCodeUsageStatus::Consumed, $approved->promoCodeUsages()->sole()->status);
        SubscriptionRequest::factory()->create();
        $history = $this->getJson('/api/student/subscription-requests')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $pendingId)->assertJsonPath('data.1.status', 'approved')
            ->assertJsonPath('data.2.status', 'rejected')->assertJsonPath('data.2.pricing.final_price', 80)
            ->assertJsonPath('data.2.plan.price', 100)->assertJsonPath('data.2.handled_at', $rejected->fresh()->handled_at->toIso8601String());
        $this->assertArrayNotHasKey('handled_by', $history->json('data.2'));
        $this->getJson('/api/student/subscription-requests?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 3);
        Livewire::actingAs($admin, 'admin')->test(ListSubscriptionRequests::class)
            ->filterTable('status', 'rejected')->assertCanSeeTableRecords([$rejected])->assertCanNotSeeTableRecords([$approved]);
    }

    public function test_admin_has_only_immediate_payment_fields_and_final_status_filters(): void
    {
        $this->assertSame(['none', 'active', 'expired', 'cancelled'], array_column(SubscriptionStatus::cases(), 'value'));
        $this->assertSame(['pending', 'approved', 'rejected'], array_column(SubscriptionRequestStatus::cases(), 'value'));
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->for($plan)->create();
        $admin = $this->superAdmin();
        Livewire::actingAs($admin, 'admin')->test(ListSubscriptionRequests::class)
            ->mountAction(TestAction::make('approve')->table($request))
            ->assertFormFieldDoesNotExist('starts_at')
            ->assertFormFieldExists('paid_at')
            ->assertFormFieldExists('proof');
        $list = Livewire::actingAs($admin, 'admin')->test(ListStudentSubscriptions::class);
        $this->assertSame(['active', 'expired', 'cancelled'], array_keys($list->instance()->getTable()->getFilter('status')->getOptions()));
        $list->mountAction('newSubscription')->assertFormFieldDoesNotExist('starts_at');

        $subscription = $this->pay($this->unsubscribedStudent(), $plan)->subscription;
        Livewire::actingAs($admin, 'admin')
            ->test(PeriodsRelationManager::class, [
                'ownerRecord' => $subscription,
                'pageClass' => ViewStudentSubscription::class,
            ])
            ->mountAction(TestAction::make('addPeriod')->table())
            ->assertFormFieldDoesNotExist('starts_at');
    }

    public function test_approval_event_is_dispatched_once_and_only_after_successful_recording(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->basic()->create();
        $service = app(SubscriptionService::class);
        $request = $service->submitRequest($student, $plan, BillingCycle::Monthly);
        Event::fake([SubscriptionApproved::class]);
        try {
            $service->recordPeriod(new RecordedPayment(
                student: $student, plan: $plan, cycle: BillingCycle::Monthly, amount: 10,
                proof: '/no/such/lifecycle-proof.jpg', request: $request,
            ));
            $this->fail('Missing proof should fail.');
        } catch (FileDoesNotExist) {
            Event::assertNotDispatched(SubscriptionApproved::class);
        }
        $this->pay($student, $plan, request: $request);
        try {
            $this->pay($student, $plan, request: $request);
            $this->fail('Duplicate approval should fail.');
        } catch (SubscriptionException $exception) {
            $this->assertSame('REQUEST_NOT_PENDING', $exception->errorCode);
        }
        Event::assertDispatchedTimes(SubscriptionApproved::class, 1);
    }

    public function test_history_requires_authentication(): void
    {
        $this->getJson('/api/student/subscription-requests')->assertUnauthorized();
    }

    private function assertNoEntitlement(): void
    {
        foreach (['study/subjects', 'fellowships', 'report-cases', 'private-books'] as $path) {
            $this->getJson('/api/student/'.$path)->assertOk()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
        }
        $dashboard = $this->getJson('/api/student/dashboard')->assertOk();
        foreach (['subjects', 'fellowships', 'report_cases', 'continue_learning', 'recent_activity'] as $section) {
            $dashboard->assertJsonPath('data.'.$section, []);
        }
        $dashboard->assertJsonPath('data.code.subjects', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('data.code.report_cases', 'SUBSCRIPTION_REQUIRED');
    }
}
