<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionRequestStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\StudentSubscriptionResource\Pages\ListStudentSubscriptions;
use App\Filament\Resources\StudentSubscriptionResource\Pages\ViewStudentSubscription;
use App\Filament\Resources\StudentSubscriptionResource\RelationManagers\PeriodsRelationManager;
use App\Filament\Resources\SubscriptionRequestResource;
use App\Filament\Resources\SubscriptionRequestResource\Pages\ListSubscriptionRequests;
use App\Models\Plan;
use App\Models\StudentSubscription;
use App\Models\SubscriptionPeriod;
use App\Models\SubscriptionRequest;
use Database\Factories\SubscriptionPeriodFactory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The admin's side of the manual-payment flow: take a request, record the
 * payment (with its screenshot) and the plan is active; renew by adding periods.
 */
class SubscriptionAdminTest extends SubscriptionTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payment(Plan $plan, array $overrides = []): array
    {
        return array_replace([
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'amount' => 9.99,
            'currency' => 'usd',
            'paid_at' => now()->toDateTimeString(),
            'proof' => UploadedFile::fake()->image('whatsapp.jpg', 300, 500),
        ], $overrides);
    }

    // ─── The inbox ──────────────────────────────────────────────────────────

    public function test_the_inbox_lists_pending_requests_first_by_default(): void
    {
        $pending = SubscriptionRequest::factory()->create();
        $rejected = SubscriptionRequest::factory()->rejected()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubscriptionRequests::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$rejected])
            ->removeTableFilter('status')
            ->assertCanSeeTableRecords([$pending, $rejected]);
    }

    public function test_approving_a_request_records_the_payment_and_activates_the_plan(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->premium()->create();
        $request = SubscriptionRequest::factory()->create(['user_id' => $student->id, 'plan_id' => $plan->id]);
        $admin = $this->superAdmin();

        Livewire::actingAs($admin, 'admin')
            ->test(ListSubscriptionRequests::class)
            ->callAction(TestAction::make('approve')->table($request), $this->payment($plan, ['amount' => 8.5]))
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertSame(SubscriptionRequestStatus::Approved, $request->status);
        $this->assertSame($admin->id, $request->handled_by);

        $period = SubscriptionPeriod::query()->sole();
        $this->assertSame($request->id, $period->subscription_request_id);
        $this->assertSame('8.50', $period->amount);
        $this->assertSame('USD', $period->currency);
        $this->assertSame($admin->id, $period->recorded_by);
        $this->assertNotNull($period->proof(), 'The screenshot must be attached to the period.');

        // The student now has access to what the plan includes.
        $this->assertSame(SubscriptionStatus::Active, StudentSubscription::query()->where('user_id', $student->id)->sole()->status());
        $this->actingAsStudent($student);
        $this->getJson('/api/student/report-cases')->assertOk();
    }

    public function test_the_approval_form_starts_from_what_the_student_asked_for(): void
    {
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->yearly()->create(['plan_id' => $plan->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubscriptionRequests::class)
            ->mountAction(TestAction::make('approve')->table($request))
            ->assertSchemaStateSet([
                'plan_id' => $plan->id,
                'billing_cycle' => 'yearly',
                'amount' => '59.99',
                'currency' => 'USD',
            ]);
    }

    public function test_the_admin_can_record_a_different_plan_than_the_one_requested(): void
    {
        $basic = Plan::factory()->basic()->create();
        $ultra = Plan::factory()->ultra()->create();
        $request = SubscriptionRequest::factory()->create(['plan_id' => $basic->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubscriptionRequests::class)
            ->callAction(TestAction::make('approve')->table($request), $this->payment($ultra, ['billing_cycle' => 'yearly']))
            ->assertHasNoActionErrors();

        $period = SubscriptionPeriod::query()->sole();
        $this->assertSame($ultra->id, $period->plan_id);
        $this->assertSame(BillingCycle::Yearly, $period->billing_cycle);
    }

    public function test_approving_without_a_screenshot_is_refused(): void
    {
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->create(['plan_id' => $plan->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubscriptionRequests::class)
            ->callAction(TestAction::make('approve')->table($request), $this->payment($plan, ['proof' => null]))
            ->assertHasActionErrors(['proof' => 'required']);

        $this->assertSame(SubscriptionRequestStatus::Pending, $request->fresh()->status);
        $this->assertDatabaseCount('subscription_periods', 0);
    }

    public function test_the_screenshot_must_be_an_image(): void
    {
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->create(['plan_id' => $plan->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubscriptionRequests::class)
            ->callAction(TestAction::make('approve')->table($request), $this->payment($plan, [
                'proof' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
            ]))
            ->assertHasActionErrors(['proof']);

        $this->assertDatabaseCount('subscription_periods', 0);
    }

    public function test_the_cycle_must_be_one_the_chosen_plan_sells(): void
    {
        $plan = Plan::factory()->basic()->monthlyOnly()->create();
        $request = SubscriptionRequest::factory()->create(['plan_id' => $plan->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubscriptionRequests::class)
            ->callAction(TestAction::make('approve')->table($request), $this->payment($plan, ['billing_cycle' => 'yearly']))
            ->assertHasActionErrors();

        $this->assertDatabaseCount('subscription_periods', 0);
    }

    public function test_the_amount_is_required_and_not_negative(): void
    {
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->create(['plan_id' => $plan->id]);

        $admin = $this->superAdmin();

        // A failed submit leaves the modal open, so each case gets its own page.
        Livewire::actingAs($admin, 'admin')
            ->test(ListSubscriptionRequests::class)
            ->callAction(TestAction::make('approve')->table($request), $this->payment($plan, ['amount' => null]))
            ->assertHasActionErrors(['amount' => 'required']);

        Livewire::actingAs($admin, 'admin')
            ->test(ListSubscriptionRequests::class)
            ->callAction(TestAction::make('approve')->table($request), $this->payment($plan, ['amount' => -5]))
            ->assertHasActionErrors(['amount']);

        $this->assertDatabaseCount('subscription_periods', 0);
    }

    public function test_a_request_that_was_already_handled_cannot_be_approved_again(): void
    {
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->rejected()->create(['plan_id' => $plan->id]);

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubscriptionRequests::class)
            ->filterTable('status', SubscriptionRequestStatus::Rejected->value)
            ->assertTableActionHidden('approve', $request)
            ->assertTableActionHidden('reject', $request);
    }

    public function test_rejecting_a_request_keeps_the_note_and_grants_nothing(): void
    {
        $request = SubscriptionRequest::factory()->create();
        $admin = $this->superAdmin();

        Livewire::actingAs($admin, 'admin')
            ->test(ListSubscriptionRequests::class)
            ->callAction(TestAction::make('reject')->table($request), ['admin_note' => 'Could not reach the student'])
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertSame(SubscriptionRequestStatus::Rejected, $request->status);
        $this->assertSame('Could not reach the student', $request->admin_note);
        $this->assertSame($admin->id, $request->handled_by);
        $this->assertDatabaseCount('student_subscriptions', 0);
    }

    public function test_the_navigation_badge_counts_pending_requests(): void
    {
        SubscriptionRequest::factory()->count(2)->create();
        SubscriptionRequest::factory()->rejected()->create();

        $this->actingAs($this->superAdmin(), 'admin');

        $this->assertSame('2', SubscriptionRequestResource::getNavigationBadge());
    }

    // ─── Subscriptions ──────────────────────────────────────────────────────

    public function test_the_list_shows_every_students_status(): void
    {
        $active = StudentSubscription::factory()->active(Plan::factory()->basic()->create())->create();
        $expired = StudentSubscription::factory()->expired()->create();
        $cancelled = StudentSubscription::factory()->cancelled()->active()->create();

        $list = Livewire::actingAs($this->superAdmin(), 'admin')->test(ListStudentSubscriptions::class);

        $list->assertCanSeeTableRecords([$active, $expired, $cancelled]);

        $list->filterTable('status', 'active')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$expired, $cancelled]);

        $list->filterTable('status', 'expired')
            ->assertCanSeeTableRecords([$expired])
            ->assertCanNotSeeTableRecords([$active, $cancelled]);

        $list->filterTable('status', 'cancelled')
            ->assertCanSeeTableRecords([$cancelled])
            ->assertCanNotSeeTableRecords([$active, $expired]);
    }

    public function test_subscriptions_can_be_searched_by_student_name_or_email(): void
    {
        $target = StudentSubscription::factory()->active()->create();
        $other = StudentSubscription::factory()->active()->create();
        $target->user->update(['name' => 'Layla Hassan', 'email' => 'layla@example.test']);

        $list = Livewire::actingAs($this->superAdmin(), 'admin')->test(ListStudentSubscriptions::class);

        $list->searchTable('Layla')->assertCanSeeTableRecords([$target])->assertCanNotSeeTableRecords([$other]);
        $list->searchTable('layla@example')->assertCanSeeTableRecords([$target])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_admin_can_start_a_subscription_for_a_student_who_never_used_the_app(): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->basic()->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListStudentSubscriptions::class)
            ->callAction('newSubscription', $this->payment($plan) + ['student_id' => $student->id])
            ->assertHasNoActionErrors();

        $subscription = StudentSubscription::query()->where('user_id', $student->id)->sole();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status());
        $this->assertNotNull($subscription->periods->sole()->proof());
    }

    public function test_the_subscription_page_shows_the_student_and_their_state(): void
    {
        $subscription = StudentSubscription::factory()->active(Plan::factory()->premium()->create())->create();

        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewStudentSubscription::class, ['record' => $subscription->getRouteKey()])
            ->assertSee($subscription->user->name)
            ->assertSee($subscription->user->email)
            ->assertSee('Premium');
    }

    // ─── Periods (the invoice history) ──────────────────────────────────────

    private function periods(StudentSubscription $subscription, $admin = null)
    {
        return Livewire::actingAs($admin ?? $this->superAdmin(), 'admin')
            ->test(PeriodsRelationManager::class, [
                'ownerRecord' => $subscription,
                'pageClass' => ViewStudentSubscription::class,
            ]);
    }

    public function test_the_admin_adds_a_renewal_period_with_its_screenshot(): void
    {
        $this->travelTo('2026-03-10 12:34:56');
        $plan = Plan::factory()->basic()->create();
        $subscription = StudentSubscription::factory()->active($plan)->create();

        $this->periods($subscription)
            ->callAction(TestAction::make('addPeriod')->table(), $this->payment($plan, ['starts_at' => now()->addDays(5)->toDateTimeString()]))
            ->assertHasNoActionErrors();

        $renewal = $subscription->periods()->get()->last();
        $this->assertCount(2, $subscription->periods);
        $this->assertTrue($renewal->starts_at->equalTo(now()), 'A renewal starts at approval.');
        $this->assertNotNull($renewal->proof());
    }

    public function test_the_history_lists_every_period_newest_first_and_links_the_screenshot(): void
    {
        $plan = Plan::factory()->basic()->create();
        $subscription = StudentSubscription::factory()->create();
        $old = SubscriptionPeriodFactory::new()->ended()->withProof()->create(['student_subscription_id' => $subscription->id, 'plan_id' => $plan->id]);
        $new = SubscriptionPeriodFactory::new()->current()->withProof()->create(['student_subscription_id' => $subscription->id, 'plan_id' => $plan->id]);

        $this->periods($subscription)
            ->assertCanSeeTableRecords([$new, $old], inOrder: true)
            ->assertSee('View screenshot');
    }

    public function test_periods_are_append_only(): void
    {
        $subscription = StudentSubscription::factory()->active()->create();
        $period = $subscription->currentPeriod();

        $this->periods($subscription)
            ->assertTableActionDoesNotExist('edit', record: $period)
            ->assertTableActionDoesNotExist('delete', record: $period);
    }

    public function test_cancelling_and_reinstating_from_the_subscription_page(): void
    {
        $subscription = StudentSubscription::factory()->active()->create();

        $page = Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ViewStudentSubscription::class, ['record' => $subscription->getRouteKey()]);

        $page->assertActionHidden('reinstate')
            ->callAction('cancel', ['reason' => 'Refunded on WhatsApp'])
            ->assertHasNoActionErrors();

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->fresh()->status());
        $this->assertSame('Refunded on WhatsApp', $subscription->fresh()->cancellation_reason);

        // Cancelled means no access, even though the paid period has time left.
        $this->actingAsStudent($subscription->user);
        $this->getJson('/api/student/study/subjects')->assertOk()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $page->assertActionHidden('cancel')
            ->callAction('reinstate')
            ->assertHasNoActionErrors();

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status());
    }

    // ─── Who may do what ────────────────────────────────────────────────────

    public function test_the_admin_role_can_take_payments(): void
    {
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->create(['plan_id' => $plan->id]);

        Livewire::actingAs($this->adminWithRole('admin'), 'admin')
            ->test(ListSubscriptionRequests::class)
            ->callAction(TestAction::make('approve')->table($request), $this->payment($plan))
            ->assertHasNoActionErrors();

        $this->assertSame(SubscriptionRequestStatus::Approved, $request->fresh()->status);
    }

    public function test_someone_who_may_only_view_cannot_approve_reject_or_add_periods(): void
    {
        $viewer = $this->adminWithPermissions(['subscriptions.view']);
        $plan = Plan::factory()->basic()->create();
        $request = SubscriptionRequest::factory()->create(['plan_id' => $plan->id]);
        $subscription = StudentSubscription::factory()->active($plan)->create();

        Livewire::actingAs($viewer, 'admin')
            ->test(ListSubscriptionRequests::class)
            ->assertTableActionDisabled('approve', $request)
            ->assertTableActionDisabled('reject', $request);

        $this->periods($subscription, $viewer)
            ->assertActionDisabled(TestAction::make('addPeriod')->table());

        Livewire::actingAs($viewer, 'admin')
            ->test(ViewStudentSubscription::class, ['record' => $subscription->getRouteKey()])
            ->assertActionDisabled('cancel');
    }

    public function test_a_content_manager_cannot_open_requests_or_subscriptions(): void
    {
        $manager = $this->adminWithRole('content_manager');

        Livewire::actingAs($manager, 'admin')->test(ListSubscriptionRequests::class)->assertForbidden();
        Livewire::actingAs($manager, 'admin')->test(ListStudentSubscriptions::class)->assertForbidden();
    }

    // ─── The payment screenshot ─────────────────────────────────────────────

    private function periodWithProof(): SubscriptionPeriod
    {
        return SubscriptionPeriodFactory::new()->current()->withProof()->create();
    }

    public function test_an_admin_can_open_the_screenshot(): void
    {
        $period = $this->periodWithProof();

        $response = $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('filament.admin.subscription-periods.proof', $period));

        $response->assertOk();
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_screenshot_is_refused_to_an_admin_without_the_permission(): void
    {
        $period = $this->periodWithProof();

        $this->actingAs($this->adminWithRole('content_manager'), 'admin')
            ->get(route('filament.admin.subscription-periods.proof', $period))
            ->assertForbidden();
    }

    public function test_the_screenshot_is_not_reachable_without_logging_in(): void
    {
        $period = $this->periodWithProof();

        $this->get(route('filament.admin.subscription-periods.proof', $period))
            ->assertRedirect();
    }

    public function test_a_student_cannot_open_a_screenshot(): void
    {
        $period = $this->periodWithProof();

        $this->actingAs($period->subscription->user)
            ->get(route('filament.admin.subscription-periods.proof', $period))
            ->assertRedirect();
    }

    public function test_a_period_without_a_screenshot_is_not_found(): void
    {
        $period = SubscriptionPeriodFactory::new()->current()->create();

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('filament.admin.subscription-periods.proof', $period))
            ->assertNotFound();
    }

    public function test_the_screenshot_is_not_on_any_public_disk(): void
    {
        $period = $this->periodWithProof();
        $path = $period->proof()->getPathRelativeToRoot();

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        Storage::disk('filament_public')->assertMissing($path);
    }
}
