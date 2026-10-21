<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\PlanFeature;
use App\Models\Plan;
use App\Models\StudentSubscription;
use App\Models\User;
use App\Services\Subscription\FeatureAccessService;
use Database\Factories\SubscriptionPeriodFactory;

/**
 * The plan is enforced by the backend: a student reaches Library, Question Bank
 * and Case Report routes only when their active plan includes the feature, and
 * is told which of the two ways it failed.
 */
class PlanGateTest extends SubscriptionTestCase
{
    private const LIBRARY = '/api/student/study/subjects';

    private const PRIVATE_BOOKS = '/api/student/private-books';

    private const QUESTION_BANK = '/api/student/fellowships';

    private const CASE_REPORT = '/api/student/report-cases';

    private function basic(): Plan
    {
        return Plan::factory()->basic()->create();
    }

    private function premium(int $aiCredits = 100): Plan
    {
        return Plan::factory()->premium($aiCredits)->create();
    }

    // ─── No subscription ────────────────────────────────────────────────────

    public function test_a_student_with_no_subscription_is_refused_everywhere_that_is_gated(): void
    {
        $this->actingAsStudent($this->unsubscribedStudent());

        foreach ([self::LIBRARY, self::PRIVATE_BOOKS, self::QUESTION_BANK, self::CASE_REPORT] as $url) {
            $this->getJson($url)
                ->assertForbidden()
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
        }
    }

    public function test_plans_and_subscription_stay_open_without_a_subscription(): void
    {
        $this->actingAsStudent($this->unsubscribedStudent());

        $this->getJson('/api/student/plans')->assertOk();
        $this->getJson('/api/student/subscription')->assertOk();
    }

    public function test_the_gate_answers_before_the_route_lookup_so_ids_are_not_revealed(): void
    {
        $this->actingAsStudent($this->unsubscribedStudent());

        // A subject id that does not exist must not be a 404 here: that would
        // tell a student without a plan which ids are real.
        $this->getJson(self::LIBRARY.'/999999')
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_an_unauthenticated_request_is_still_a_401(): void
    {
        $this->getJson(self::LIBRARY)->assertUnauthorized();
    }

    // ─── What each plan includes ────────────────────────────────────────────

    public function test_a_basic_student_reaches_library_and_question_bank_but_not_case_report(): void
    {
        $this->actingAsStudent($this->subscribedStudent($this->basic()));

        $this->getJson(self::LIBRARY)->assertOk();
        $this->getJson(self::PRIVATE_BOOKS)->assertOk();
        $this->getJson(self::QUESTION_BANK)->assertOk();

        $this->getJson(self::CASE_REPORT)
            ->assertForbidden()
            ->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');
    }

    public function test_a_premium_student_also_reaches_case_report(): void
    {
        $this->actingAsStudent($this->subscribedStudent($this->premium()));

        $this->getJson(self::CASE_REPORT)->assertOk();
        $this->getJson(self::LIBRARY)->assertOk();
    }

    public function test_every_fellowship_and_study_route_is_gated_not_just_the_index(): void
    {
        $this->actingAsStudent($this->unsubscribedStudent());

        $this->postJson('/api/student/study/sessions', ['subject_id' => 1])->assertForbidden();
        $this->getJson('/api/student/study/highlights')->assertForbidden();
        $this->postJson('/api/student/private-books', [])->assertForbidden();
        $this->getJson('/api/student/fellowships/1/filters')->assertForbidden();
        $this->postJson('/api/student/fellowships/1/sessions', [])->assertForbidden();
        $this->getJson('/api/student/fellowship-sessions/1')->assertForbidden();
    }

    public function test_a_plan_without_library_blocks_library_routes(): void
    {
        $plan = Plan::factory()->withFeatures(PlanFeature::CaseReport)->create();

        $this->actingAsStudent($this->subscribedStudent($plan));

        $this->getJson(self::CASE_REPORT)->assertOk();
        $this->getJson(self::LIBRARY)->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');
        $this->getJson(self::PRIVATE_BOOKS)->assertForbidden()->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');
    }

    // ─── Time and state ─────────────────────────────────────────────────────

    public function test_an_expired_subscription_is_refused(): void
    {
        $student = $this->unsubscribedStudent();
        StudentSubscription::factory()->for($student, 'user')->expired($this->premium())->create();

        $this->actingAsStudent($student);

        $this->getJson(self::LIBRARY)->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_a_cancelled_subscription_is_refused_even_inside_a_paid_period(): void
    {
        $student = $this->unsubscribedStudent();
        StudentSubscription::factory()->for($student, 'user')->cancelled()->active($this->premium())->create();

        $this->actingAsStudent($student);

        $this->getJson(self::LIBRARY)->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_a_period_that_has_not_started_yet_grants_nothing(): void
    {
        $student = $this->unsubscribedStudent();
        $subscription = StudentSubscription::factory()->for($student, 'user')->create();
        SubscriptionPeriodFactory::new()->upcoming()->create([
            'student_subscription_id' => $subscription->id,
            'plan_id' => $this->premium()->id,
        ]);

        $this->actingAsStudent($student);

        $this->getJson(self::LIBRARY)->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_access_ends_exactly_when_the_period_does(): void
    {
        $student = $this->unsubscribedStudent();
        $subscription = StudentSubscription::factory()->for($student, 'user')->create();
        $period = SubscriptionPeriodFactory::new()->current()->create([
            'student_subscription_id' => $subscription->id,
            'plan_id' => $this->premium()->id,
        ]);

        $this->actingAsStudent($student);

        $this->travelTo($period->ends_at->copy()->subSecond());
        $this->getJson(self::LIBRARY)->assertOk();

        $this->travelTo($period->ends_at);
        $this->getJson(self::LIBRARY)->assertForbidden();
    }

    public function test_a_payment_recorded_after_a_refusal_takes_effect_on_the_next_request(): void
    {
        $student = $this->unsubscribedStudent();
        $this->actingAsStudent($student);

        $this->getJson(self::LIBRARY)->assertForbidden();

        $this->subscribe($student, $this->basic());

        $this->getJson(self::LIBRARY)->assertOk();
    }

    public function test_when_periods_overlap_the_one_that_started_last_decides_the_plan(): void
    {
        $student = $this->unsubscribedStudent();
        $subscription = StudentSubscription::factory()->for($student, 'user')->create();

        // A Basic month already running, then an upgrade to Premium that began
        // an hour ago.
        SubscriptionPeriodFactory::new()->current()->create([
            'student_subscription_id' => $subscription->id,
            'plan_id' => $this->basic()->id,
        ]);
        SubscriptionPeriodFactory::new()->create([
            'student_subscription_id' => $subscription->id,
            'plan_id' => $this->premium()->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->actingAsStudent($student);

        $this->getJson(self::CASE_REPORT)->assertOk();
    }

    // ─── The access service ─────────────────────────────────────────────────

    public function test_the_access_service_lists_only_the_plans_features_in_product_order(): void
    {
        $student = $this->subscribedStudent($this->premium(250));
        $access = app(FeatureAccessService::class);

        $this->assertSame(
            ['library', 'question_bank', 'case_report', 'ai'],
            $access->featuresFor($student)->map(fn ($limit) => $limit->feature->value)->all(),
        );
        $this->assertTrue($access->hasFeature($student, PlanFeature::Ai));
        $this->assertSame(250, $access->limitFor($student, PlanFeature::Ai)->limit);
    }

    public function test_the_access_service_reports_nothing_for_a_student_without_a_plan(): void
    {
        $student = User::factory()->create();
        $access = app(FeatureAccessService::class);

        $this->assertTrue($access->featuresFor($student)->isEmpty());
        $this->assertFalse($access->hasFeature($student, PlanFeature::Library));
        $this->assertNull($access->limitFor($student, PlanFeature::Ai));
    }
}
