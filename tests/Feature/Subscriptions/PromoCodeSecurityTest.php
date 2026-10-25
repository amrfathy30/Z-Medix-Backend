<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\BillingCycle;
use App\Enums\PromoCodeUsageStatus;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\SubscriptionRequest;
use App\Services\Subscription\RecordedPayment;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class PromoCodeSecurityTest extends SubscriptionTestCase
{
    private const REQUESTS = '/api/student/subscription-requests';

    private const VALIDATE = '/api/student/subscription/promo-code/validate';

    #[DataProvider('changedEligibility')]
    public function test_previous_validation_does_not_authorize_later_submission(string $change, string $error): void
    {
        $this->travelTo(now()->startOfSecond());
        $promo = PromoCode::factory()->create(['max_usage_limit' => 1]);
        $plan = Plan::factory()->create();
        $this->actingAsStudent($this->unsubscribedStudent());
        $payload = ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => $promo->code];
        $this->postJson(self::VALIDATE, $payload)->assertOk();
        $this->changeEligibility($promo, $plan, $change);
        $requestCount = SubscriptionRequest::query()->count();
        $usageCount = PromoCodeUsage::query()->count();

        $this->postJson(self::REQUESTS, $payload + ['is_valid' => true])
            ->assertUnprocessable()->assertJsonPath('success', false)->assertJsonPath('code', $error);

        $this->assertDatabaseCount('subscription_requests', $requestCount);
        $this->assertDatabaseCount('promo_code_usages', $usageCount);
    }

    public static function changedEligibility(): array
    {
        return [
            ['expired', 'PROMO_CODE_EXPIRED'], ['inactive', 'PROMO_CODE_INACTIVE'],
            ['deleted', 'PROMO_CODE_INVALID'], ['future', 'PROMO_CODE_NOT_STARTED'],
            ['exhausted', 'PROMO_CODE_EXHAUSTED'],
        ];
    }

    private function changeEligibility(PromoCode $promo, Plan $plan, string $change): void
    {
        match ($change) {
            'expired' => $promo->update(['valid_until' => now()->subSecond()]),
            'inactive' => $promo->update(['is_active' => false]),
            'deleted' => $promo->delete(),
            'future' => $promo->update(['valid_from' => now()->addMinute()]),
            'exhausted' => app(SubscriptionService::class)->submitRequest(
                $this->unsubscribedStudent(), $plan, BillingCycle::Monthly, $promo->code,
            ),
        };
    }

    #[DataProvider('invalidPendingCodes')]
    public function test_identical_pending_resubmissions_revalidate_and_preserve_original_reservation(string $change, string $error): void
    {
        $this->travelTo(now()->startOfSecond());
        $promo = PromoCode::factory()->create();
        $plan = Plan::factory()->create(['monthly_price' => 100]);
        $this->actingAsStudent($this->unsubscribedStudent());
        $payload = ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => $promo->code];
        $id = $this->postJson(self::REQUESTS, $payload)->assertCreated()->json('data.request.id');
        $snapshot = SubscriptionRequest::findOrFail($id)->pricing_snapshot;
        $this->changeEligibility($promo, $plan, $change);

        $this->postJson(self::REQUESTS, $payload)->assertUnprocessable()->assertJsonPath('code', $error);

        $this->assertDatabaseCount('subscription_requests', 1);
        $this->assertDatabaseCount('promo_code_usages', 1);
        $this->assertSame($snapshot, SubscriptionRequest::findOrFail($id)->pricing_snapshot);
        $this->assertSame(PromoCodeUsageStatus::Reserved, PromoCodeUsage::query()->sole()->status);
    }

    public static function invalidPendingCodes(): array
    {
        return array_slice(self::changedEligibility(), 0, 4);
    }

    public function test_reserved_code_cannot_be_resubmitted_on_same_or_different_selection(): void
    {
        $promo = PromoCode::factory()->create();
        $plan = Plan::factory()->create();
        $this->actingAsStudent($this->unsubscribedStudent());
        $payload = ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => $promo->code];
        $this->postJson(self::REQUESTS, $payload)->assertCreated();
        foreach (['monthly', 'yearly'] as $cycle) {
            $this->postJson(self::REQUESTS, array_replace($payload, ['billing_cycle' => $cycle]))
                ->assertUnprocessable()->assertJsonPath('code', 'PROMO_CODE_ALREADY_USED');
        }
        $this->assertDatabaseCount('promo_code_usages', 1);
        $this->assertSame(PromoCodeUsageStatus::Reserved, PromoCodeUsage::query()->sole()->status);
    }

    public function test_consumed_usage_blocks_creation_and_released_usage_allows_it(): void
    {
        $promo = PromoCode::factory()->create();
        $plan = Plan::factory()->create();
        $student = $this->actingAsStudent($this->unsubscribedStudent());
        $payload = ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => $promo->code];
        $request = app(SubscriptionService::class)->submitRequest($student, $plan, BillingCycle::Monthly, $promo->code);
        app(SubscriptionService::class)->rejectRequest($request);
        $id = $this->postJson(self::REQUESTS, $payload)->assertCreated()->json('data.request.id');
        $request = SubscriptionRequest::findOrFail($id);
        app(SubscriptionService::class)->recordPeriod(new RecordedPayment(
            student: $student, plan: $plan, cycle: BillingCycle::Monthly,
            amount: $request->pricing_snapshot['final_price'], proof: UploadedFile::fake()->image('proof.jpg'), request: $request,
        ));
        $this->postJson(self::REQUESTS, $payload)->assertUnprocessable()->assertJsonPath('code', 'PROMO_CODE_ALREADY_USED');
        $this->assertDatabaseCount('subscription_requests', 2);
        $this->assertDatabaseCount('promo_code_usages', 2);
        $this->assertSame(['released', 'consumed'], PromoCodeUsage::query()->orderBy('id')->pluck('status')->map(fn (PromoCodeUsageStatus $status): string => $status->value)->all());
    }

    public function test_direct_submission_ignores_all_frontend_pricing_and_uses_current_plan_values(): void
    {
        $promo = PromoCode::factory()->create(['discount_percentage' => 20]);
        $otherPromo = PromoCode::factory()->create(['discount_percentage' => 100]);
        $plan = Plan::factory()->create(['monthly_price' => '100.05', 'currency' => 'EGP']);
        $this->actingAsStudent($this->unsubscribedStudent());
        $payload = ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => $promo->code,
            'original_price' => 0, 'discount_amount' => 999999, 'final_price' => -1,
            'discount_percentage' => 100, 'promo_code_id' => $otherPromo->id, 'is_valid' => true,
            'pricing_snapshot' => ['final_price' => 0, 'currency' => 'USD']];
        $this->postJson(self::REQUESTS, $payload)->assertCreated()
            ->assertJsonPath('data.request.pricing.original_price', 100.05)
            ->assertJsonPath('data.request.pricing.discount_percentage', 20)
            ->assertJsonPath('data.request.pricing.discount_amount', 20.01)
            ->assertJsonPath('data.request.pricing.final_price', 80.04)
            ->assertJsonPath('data.request.pricing.currency', 'EGP');
        $this->assertSame($promo->id, PromoCodeUsage::query()->sole()->promo_code_id);
    }

    public function test_creation_uses_price_and_percentage_changed_after_preview(): void
    {
        $promo = PromoCode::factory()->create(['discount_percentage' => 20]);
        $plan = Plan::factory()->create(['monthly_price' => 100]);
        $this->actingAsStudent($this->unsubscribedStudent());
        $payload = ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => $promo->code];
        $this->postJson(self::VALIDATE, $payload)->assertOk()->assertJsonPath('data.final_price', 80);
        $promo->update(['discount_percentage' => 10]);
        $plan->update(['monthly_price' => 200]);
        $this->postJson(self::REQUESTS, $payload)->assertCreated()->assertJsonPath('data.request.pricing.original_price', 200)
            ->assertJsonPath('data.request.pricing.discount_percentage', 10)->assertJsonPath('data.request.pricing.final_price', 180);
    }

    #[DataProvider('invalidPercentages')]
    public function test_invalid_stored_discount_is_rejected_without_creating_a_request(string $percentage): void
    {
        $promo = PromoCode::factory()->create();
        DB::table('promo_codes')->where('id', $promo->id)->update(['discount_percentage' => $percentage]);
        $plan = Plan::factory()->create();
        $this->actingAsStudent($this->unsubscribedStudent());
        $this->postJson(self::REQUESTS, ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => $promo->code])
            ->assertUnprocessable()->assertJsonPath('code', 'PROMO_CODE_INVALID');
        $this->assertDatabaseCount('subscription_requests', 0);
        $this->assertDatabaseCount('promo_code_usages', 0);
    }

    public static function invalidPercentages(): array
    {
        return [['-1.00'], ['101.00'], ['100.001'], ['20.001']];
    }

    public function test_replacing_pending_promo_releases_only_the_old_reservation(): void
    {
        $old = PromoCode::factory()->create();
        $new = PromoCode::factory()->create(['discount_percentage' => 30]);
        $plan = Plan::factory()->create(['monthly_price' => 100]);
        $this->actingAsStudent($this->unsubscribedStudent());
        $payload = ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => $old->code];
        $id = $this->postJson(self::REQUESTS, $payload)->assertCreated()->json('data.request.id');
        $this->postJson(self::REQUESTS, array_replace($payload, ['promo_code' => $new->code]))
            ->assertOk()->assertJsonPath('data.request.id', $id)->assertJsonPath('data.request.pricing.code', $new->code)
            ->assertJsonPath('data.request.pricing.final_price', 70);
        $this->assertSame(PromoCodeUsageStatus::Released, $old->usages()->sole()->status);
        $this->assertSame(PromoCodeUsageStatus::Reserved, $new->usages()->sole()->status);
        $this->assertDatabaseCount('subscription_requests', 1);
    }

    #[DataProvider('rollbackContexts')]
    public function test_usage_insert_failure_rolls_back_request_and_reservation_changes(bool $hasPending): void
    {
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->create();
        $old = PromoCode::factory()->create();
        $new = PromoCode::factory()->create();
        $service = app(SubscriptionService::class);
        $pending = $hasPending ? $service->submitRequest($student, $plan, BillingCycle::Monthly, $old->code) : null;
        $snapshot = $pending?->pricing_snapshot;
        $event = 'eloquent.created: '.PromoCodeUsage::class;
        Event::listen($event, function (): void {
            throw new RuntimeException('Simulated failure after usage insert.');
        });
        try {
            $service->submitRequest($student, $plan, BillingCycle::Yearly, $new->code);
            $this->fail('Usage creation should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure after usage insert.', $exception->getMessage());
            $this->assertDatabaseCount('subscription_requests', $hasPending ? 1 : 0);
            $this->assertDatabaseCount('promo_code_usages', $hasPending ? 1 : 0);
            if ($pending !== null) {
                $this->assertSame($snapshot, $pending->fresh()->pricing_snapshot);
                $this->assertSame(BillingCycle::Monthly, $pending->fresh()->billing_cycle);
                $this->assertSame(PromoCodeUsageStatus::Reserved, $old->usages()->sole()->status);
            }
        } finally {
            Event::forget($event);
        }
    }

    public static function rollbackContexts(): array
    {
        return ['new request' => [false], 'pending replacement' => [true]];
    }
}
