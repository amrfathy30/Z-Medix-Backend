<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\BillingCycle;
use App\Enums\PromoCodeUsageStatus;
use App\Exceptions\Subscription\SubscriptionException;
use App\Filament\Resources\PromoCodeResource\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodeResource\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodeResource\Pages\ListPromoCodes;
use App\Filament\Resources\PromoCodeResource\Pages\ViewPromoCode;
use App\Filament\Resources\PromoCodeResource\RelationManagers\UsagesRelationManager;
use App\Filament\Resources\SubscriptionRequestResource\Pages\ListSubscriptionRequests;
use App\Filament\Resources\SubscriptionRequestResource\Pages\ViewSubscriptionRequest;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\SubscriptionRequest;
use App\Services\Subscription\PromoCodeService;
use App\Services\Subscription\RecordedPayment;
use App\Services\Subscription\SubscriptionService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class PromoCodeTest extends SubscriptionTestCase
{
    private const VALIDATE = '/api/student/subscription/promo-code/validate';

    private const REQUESTS = '/api/student/subscription-requests';

    private function submit(PromoCode $promo, ?Plan $plan = null): SubscriptionRequest
    {
        return app(SubscriptionService::class)->submitRequest(
            $this->unsubscribedStudent(), $plan ?? Plan::factory()->create(), BillingCycle::Monthly, $promo->code,
        );
    }

    public function test_validation_normalizes_codes_calculates_pricing_and_never_reserves(): void
    {
        $promo = PromoCode::factory()->create(['code' => ' medix20 ']);
        $plan = Plan::factory()->create(['monthly_price' => 1000, 'currency' => 'EGP']);
        $this->actingAsStudent($this->unsubscribedStudent());
        for ($i = 0; $i < 2; $i++) {
            $this->postJson(self::VALIDATE, ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => 'medix20'])
                ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.valid', true)
                ->assertJsonPath('data.code', 'MEDIX20')->assertJsonPath('data.original_price', 1000)
                ->assertJsonPath('data.discount_percentage', 20)->assertJsonPath('data.discount_amount', 200)
                ->assertJsonPath('data.final_price', 800)->assertJsonPath('data.currency', 'EGP');
        }
        $this->assertSame('MEDIX20', $promo->code);
        $this->assertDatabaseCount('promo_code_usages', 0);
        $this->assertDatabaseCount('subscription_requests', 0);
    }

    public function test_submission_saves_server_prices_and_retries_preserve_the_snapshot(): void
    {
        $promo = PromoCode::factory()->create();
        $plan = Plan::factory()->create(['monthly_price' => '10.05']);
        $student = $this->actingAsStudent($this->unsubscribedStudent());
        $payload = ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => $promo->code, 'final_price' => 0];
        $id = $this->postJson(self::REQUESTS, $payload)->assertCreated()->assertJsonPath('data.request.pricing.final_price', 8.04)->json('data.request.id');
        $snapshot = SubscriptionRequest::findOrFail($id)->pricing_snapshot;
        $promo->update(['discount_percentage' => 80, 'is_active' => false]);
        $plan->update(['monthly_price' => 500]);
        $promo->delete();
        $this->postJson(self::REQUESTS, $payload)->assertOk()->assertJsonPath('data.request.pricing', $snapshot);
        $this->getJson('/api/student/subscription')->assertJsonPath('data.pending_request.pricing', $snapshot);
        $usage = PromoCodeUsage::query()->sole();
        $this->assertSame('10.05', $usage->original_price);
        $this->assertSame('8.04', $usage->final_price);
        $this->assertSame(PromoCodeUsageStatus::Reserved, $usage->status);
        $this->assertSame($student->id, $usage->user_id);
        $this->assertSame($promo->id, $usage->promoCode->id);
    }

    #[DataProvider('invalidCodes')]
    public function test_invalid_codes_are_rejected_consistently(string $kind, string $error): void
    {
        $this->travelTo(now()->startOfSecond());
        $promo = PromoCode::factory()->create();
        match ($kind) {
            'inactive' => $promo->update(['is_active' => false]),
            'future' => $promo->update(['valid_from' => now()->addHour()]),
            'expired' => $promo->update(['valid_from' => now()->subDays(2), 'valid_until' => now()->subSecond()]),
            'deleted' => $promo->delete(),
            default => null,
        };
        $plan = Plan::factory()->create();
        $this->actingAsStudent($this->unsubscribedStudent());
        $payload = ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => $kind === 'missing' ? 'UNKNOWN' : $promo->code];
        foreach ([self::VALIDATE, self::REQUESTS] as $url) {
            $this->postJson($url, $payload)->assertUnprocessable()->assertJsonPath('success', false)->assertJsonPath('code', $error);
        }
        $this->assertDatabaseCount('promo_code_usages', 0);
        $this->assertDatabaseCount('subscription_requests', 0);
    }

    public static function invalidCodes(): array
    {
        return [
            ['missing', 'PROMO_CODE_INVALID'], ['inactive', 'PROMO_CODE_INACTIVE'],
            ['future', 'PROMO_CODE_NOT_STARTED'], ['expired', 'PROMO_CODE_EXPIRED'], ['deleted', 'PROMO_CODE_INVALID'],
        ];
    }

    public function test_validity_boundaries_are_inclusive(): void
    {
        $this->travelTo(now()->startOfSecond());
        $promo = PromoCode::factory()->create(['valid_from' => now(), 'valid_until' => now()->addHour()]);
        $student = $this->unsubscribedStudent();
        $plan = Plan::factory()->create();
        $service = app(PromoCodeService::class);
        $this->assertTrue($service->validate($student, $plan, BillingCycle::Monthly, $promo->code)['valid']);
        $this->travelTo($promo->valid_until);
        $this->assertTrue($service->validate($student, $plan, BillingCycle::Monthly, $promo->code)['valid']);
        $this->travel(1)->seconds();
        $this->expectException(SubscriptionException::class);
        $service->validate($student, $plan, BillingCycle::Monthly, $promo->code);
    }

    #[DataProvider('roundingCases')]
    public function test_percentage_rounding_uses_minor_units(string $price, string $percent, float $discount, float $final): void
    {
        $plan = Plan::factory()->create(['monthly_price' => $price]);
        $pricing = app(PromoCodeService::class)->pricing($plan, BillingCycle::Monthly, $percent);
        $this->assertEquals($discount, $pricing['discount_amount']);
        $this->assertEquals($final, $pricing['final_price']);
    }

    public static function roundingCases(): array
    {
        return [['0.05', '10.00', 0.01, 0.04], ['5.99', '33.33', 2.00, 3.99], ['1.01', '100.00', 1.01, 0], ['100.00', '0.00', 0, 100]];
    }

    public function test_all_plans_and_both_billing_cycles_are_eligible(): void
    {
        $promo = PromoCode::factory()->create();
        $student = $this->unsubscribedStudent();
        foreach ([Plan::factory()->basic()->create(), Plan::factory()->premium()->create(), Plan::factory()->ultra()->create()] as $plan) {
            foreach (BillingCycle::cases() as $cycle) {
                $this->assertTrue(app(PromoCodeService::class)->validate($student, $plan, $cycle, $promo->code)['valid']);
            }
        }
    }

    public function test_reserved_and_consumed_usage_prevent_reuse_and_exhaust_capacity(): void
    {
        $promo = PromoCode::factory()->create(['max_usage_limit' => 1]);
        $request = $this->submit($promo);
        foreach (['reserved', 'consumed'] as $status) {
            $request->promoCodeUsages()->sole()->update(['status' => $status]);
            $this->actingAsStudent($request->user);
            $payload = ['plan_id' => $request->plan_id, 'billing_cycle' => 'monthly', 'promo_code' => $promo->code];
            $this->postJson(self::VALIDATE, $payload)->assertJsonPath('code', 'PROMO_CODE_ALREADY_USED');
            $this->actingAsStudent($this->unsubscribedStudent());
            $this->postJson(self::VALIDATE, $payload)->assertJsonPath('code', 'PROMO_CODE_EXHAUSTED');
            $this->postJson(self::REQUESTS, $payload)->assertJsonPath('code', 'PROMO_CODE_EXHAUSTED');
        }
        $this->assertDatabaseCount('subscription_requests', 1);
    }

    public function test_rejection_releases_capacity_and_allows_the_student_to_retry(): void
    {
        $promo = PromoCode::factory()->create(['max_usage_limit' => 1]);
        $request = $this->submit($promo);
        $service = app(SubscriptionService::class);
        $service->rejectRequest($request);
        try {
            $service->rejectRequest($request);
            $this->fail('Duplicate rejection must keep the existing conflict behavior.');
        } catch (SubscriptionException $exception) {
            $this->assertSame('REQUEST_NOT_PENDING', $exception->errorCode);
        }
        $new = $service->submitRequest($request->user, $request->plan, BillingCycle::Monthly, $promo->code);
        $this->assertNotSame($request->id, $new->id);
        $this->assertSame(['released', 'reserved'], PromoCodeUsage::query()->orderBy('id')->pluck('status')->map(fn ($status) => $status->value)->all());
    }

    public function test_approval_consumes_exactly_one_usage_even_for_deleted_codes(): void
    {
        $promo = PromoCode::factory()->create();
        $request = $this->submit($promo);
        $promo->delete();
        $payment = new RecordedPayment(student: $request->user, plan: $request->plan, cycle: $request->billing_cycle,
            amount: 8, proof: UploadedFile::fake()->image('proof.jpg'), request: $request);
        Event::fake();
        $service = app(SubscriptionService::class);
        $service->recordPeriod($payment);
        $this->assertSame(PromoCodeUsageStatus::Consumed, $request->promoCodeUsages()->sole()->status);
        try {
            $service->recordPeriod($payment);
            $this->fail('Duplicate approval must not record a second payment.');
        } catch (SubscriptionException $exception) {
            $this->assertSame('REQUEST_NOT_PENDING', $exception->errorCode);
        }
        $this->assertDatabaseCount('subscription_periods', 1);
        $this->assertDatabaseCount('promo_code_usages', 1);
        $promo->restore();
        $this->actingAsStudent($request->user);
        $this->postJson(self::VALIDATE, ['plan_id' => $request->plan_id, 'billing_cycle' => 'monthly', 'promo_code' => $promo->code])
            ->assertJsonPath('code', 'PROMO_CODE_ALREADY_USED');
    }

    public function test_changing_pending_selection_releases_previous_reservation_and_keeps_history(): void
    {
        $promo = PromoCode::factory()->create();
        $request = $this->submit($promo);
        $original = $request->promoCodeUsages()->sole();
        $plan = Plan::factory()->create(['monthly_price' => 300]);
        $service = app(SubscriptionService::class);
        $updated = $service->submitRequest($request->user, $plan, BillingCycle::Monthly, $promo->code);
        $this->assertSame($request->id, $updated->id);
        $this->assertSame(PromoCodeUsageStatus::Released, $original->fresh()->status);
        $this->assertSame('300.00', $updated->promoCodeUsages()->latest('id')->first()->original_price);
        $service->submitRequest($request->user, $plan, BillingCycle::Monthly);
        $this->assertSame(0, $promo->usages()->where('status', 'reserved')->count());
        $this->assertNull($request->fresh()->pricing_snapshot['code']);
        $this->assertDatabaseCount('subscription_requests', 1);
    }

    public function test_failed_pending_update_rolls_back_release_and_price_changes(): void
    {
        $promo = PromoCode::factory()->create();
        $request = $this->submit($promo);
        $snapshot = $request->pricing_snapshot;
        try {
            app(SubscriptionService::class)->submitRequest($request->user, $request->plan, BillingCycle::Yearly, 'INVALID');
            $this->fail('Invalid code must reject the update.');
        } catch (SubscriptionException) {
            $this->assertSame($snapshot, $request->fresh()->pricing_snapshot);
            $this->assertSame(PromoCodeUsageStatus::Reserved, $request->promoCodeUsages()->sole()->status);
        }
    }

    public function test_validation_requires_authentication_and_valid_pricing_fields(): void
    {
        $this->postJson(self::VALIDATE)->assertUnauthorized();
        $this->actingAsStudent($this->unsubscribedStudent());
        $this->postJson(self::VALIDATE)->assertJsonValidationErrors(['plan_id', 'billing_cycle', 'promo_code']);
        $plan = Plan::factory()->monthlyOnly()->create();
        $promo = PromoCode::factory()->create();
        $payload = ['plan_id' => $plan->id, 'billing_cycle' => 'yearly', 'promo_code' => $promo->code];
        $this->postJson(self::VALIDATE, $payload)->assertJsonPath('code', 'BILLING_CYCLE_NOT_OFFERED');
        $plan->update(['is_active' => false]);
        $payload['billing_cycle'] = 'monthly';
        $this->postJson(self::VALIDATE, $payload)->assertJsonPath('code', 'PLAN_UNAVAILABLE');
    }

    public function test_admin_can_create_edit_disable_and_soft_delete_codes(): void
    {
        Livewire::actingAs($this->superAdmin(), 'admin')->test(CreatePromoCode::class)
            ->fillForm(['code' => 'SAVE20', 'discount_percentage' => 20, 'max_usage_limit' => 10,
                'valid_from' => now()->subDay()->toDateTimeString(), 'valid_until' => now()->addDay()->toDateTimeString()])
            ->call('create')->assertHasNoFormErrors();
        $promo = PromoCode::query()->sole();
        $this->assertTrue($promo->is_active);
        Livewire::test(EditPromoCode::class, ['record' => $promo->id])
            ->fillForm(['discount_percentage' => 30])->call('save')->assertHasNoFormErrors();
        $this->assertSame('30.00', $promo->fresh()->discount_percentage);
        Livewire::test(ListPromoCodes::class)
            ->callAction(TestAction::make('toggleActive')->table($promo))->assertHasNoActionErrors();
        $this->assertFalse($promo->fresh()->is_active);
        Livewire::test(EditPromoCode::class, ['record' => $promo->id])->callAction('delete')->assertHasNoActionErrors();
        $this->assertSoftDeleted($promo);
        Livewire::test(ViewPromoCode::class, ['record' => $promo->id])->assertOk();
    }

    public function test_admin_validation_rejects_bad_configuration_and_case_insensitive_duplicates(): void
    {
        PromoCode::factory()->create(['code' => 'SAVE20']);
        Livewire::actingAs($this->superAdmin(), 'admin')->test(CreatePromoCode::class)
            ->fillForm(['code' => 'save20', 'discount_percentage' => 101, 'max_usage_limit' => 0,
                'valid_from' => now()->toDateTimeString(), 'valid_until' => now()->subDay()->toDateTimeString()])
            ->call('create')->assertHasFormErrors(['discount_percentage', 'max_usage_limit', 'valid_until']);
        try {
            PromoCode::factory()->create(['code' => 'save20']);
            $this->fail('Codes must be unique regardless of casing.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
    }

    public function test_admin_cannot_lower_capacity_below_allocations_or_rename_a_code(): void
    {
        $promo = PromoCode::factory()->create();
        $this->submit($promo);
        $this->submit($promo);
        Livewire::actingAs($this->superAdmin(), 'admin')->test(EditPromoCode::class, ['record' => $promo->id])
            ->fillForm(['max_usage_limit' => 1])->call('save')->assertHasFormErrors(['max_usage_limit']);
        try {
            $promo->update(['code' => 'RENAMED']);
            $this->fail('Code identities must remain stable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
    }

    public function test_filament_counts_history_and_request_details_use_snapshots(): void
    {
        $promo = PromoCode::factory()->create(['max_usage_limit' => 5]);
        $request = $this->submit($promo);
        $other = $this->submit($promo);
        app(SubscriptionService::class)->rejectRequest($other);
        $consumed = $this->submit($promo)->promoCodeUsages()->sole();
        $consumed->update(['status' => 'consumed']);
        $promo->update(['discount_percentage' => 90]);
        $request->plan->update(['monthly_price' => 500]);
        $counts = PromoCode::withUsageCounts()->findOrFail($promo->id);
        $this->assertSame(1, $counts->consumed_count);
        $this->assertSame(1, $counts->reserved_count);
        $this->assertSame(3, $counts->available_count);
        Livewire::actingAs($this->superAdmin(), 'admin')->test(ListPromoCodes::class)->assertCanSeeTableRecords([$promo])->searchTable($promo->code)->assertCanSeeTableRecords([$promo]);
        Livewire::test(ViewPromoCode::class, ['record' => $promo->id])->assertOk();
        Livewire::test(UsagesRelationManager::class, ['ownerRecord' => $promo, 'pageClass' => ViewPromoCode::class])
            ->assertCanSeeTableRecords($promo->usages)->assertTableColumnStateSet('discount_percentage', '20.00', $consumed);
        Livewire::test(ViewSubscriptionRequest::class, ['record' => $request->id])->assertOk()->assertSee($promo->code);
    }

    public function test_unprivileged_admin_cannot_manage_codes_or_view_history(): void
    {
        $promo = PromoCode::factory()->create();
        Livewire::actingAs($this->adminWithPermissions([]), 'admin')->test(ListPromoCodes::class)->assertForbidden();
        Livewire::test(CreatePromoCode::class)->assertForbidden();
        Livewire::test(EditPromoCode::class, ['record' => $promo->id])->assertForbidden();
        Livewire::test(ViewPromoCode::class, ['record' => $promo->id])->assertForbidden();
        Livewire::test(UsagesRelationManager::class, ['ownerRecord' => $promo, 'pageClass' => ViewPromoCode::class])->assertForbidden();
    }

    public function test_usage_snapshots_are_immutable(): void
    {
        $usage = $this->submit(PromoCode::factory()->create())->promoCodeUsages()->sole();
        $this->expectException(\LogicException::class);
        $usage->update(['final_price' => 0]);
    }

    public function test_another_students_reservation_does_not_block_eligibility_with_capacity_remaining(): void
    {
        $promo = PromoCode::factory()->create(['max_usage_limit' => 2]);
        $request = $this->submit($promo);
        $this->actingAsStudent($this->unsubscribedStudent());
        $this->postJson(self::VALIDATE, ['plan_id' => $request->plan_id, 'billing_cycle' => 'monthly', 'promo_code' => $promo->code])
            ->assertOk()->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.usages');
    }

    public function test_admin_credentials_cannot_submit_student_promo_requests(): void
    {
        $this->actingAs($this->superAdmin(), 'sanctum');
        $this->postJson(self::VALIDATE)->assertForbidden();
        $this->postJson(self::REQUESTS)->assertForbidden();
    }

    public function test_error_messages_follow_the_existing_translation_directory(): void
    {
        $plan = Plan::factory()->create();
        $this->actingAsStudent($this->unsubscribedStudent());
        $this->postJson(self::VALIDATE, ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => 'MISSING'])
            ->assertJsonPath('message', 'This promo code is invalid.');
        $this->postJson(self::VALIDATE.'?lang=ar', ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => 'MISSING'])
            ->assertJsonPath('message', 'كود الخصم غير صالح.');
    }

    public function test_approval_form_uses_saved_discounted_price_after_price_changes(): void
    {
        $promo = PromoCode::factory()->create(['discount_percentage' => 20]);
        $request = $this->submit($promo, Plan::factory()->create(['monthly_price' => 100, 'currency' => 'EGP']));
        $request->plan->update(['monthly_price' => 500, 'currency' => 'USD']);
        $promo->update(['discount_percentage' => 50]);
        Livewire::actingAs($this->superAdmin(), 'admin')
            ->test(ListSubscriptionRequests::class)
            ->mountAction(TestAction::make('approve')->table($request))
            ->assertSchemaStateSet(['amount' => '80.00', 'currency' => 'EGP']);
    }

    public function test_disabled_existing_reservation_survives_failed_selection_change_and_releases_on_rejection(): void
    {
        $promo = PromoCode::factory()->create();
        $request = $this->submit($promo);
        $promo->update(['is_active' => false]);
        $this->actingAsStudent($request->user);
        $this->postJson(self::REQUESTS, ['plan_id' => $request->plan_id, 'billing_cycle' => 'yearly', 'promo_code' => $promo->code])
            ->assertJsonPath('code', 'PROMO_CODE_INACTIVE');
        $this->assertSame(PromoCodeUsageStatus::Reserved, $request->promoCodeUsages()->sole()->status);
        $this->assertSame(BillingCycle::Monthly, $request->fresh()->billing_cycle);
        app(SubscriptionService::class)->rejectRequest($request);
        $this->assertSame(PromoCodeUsageStatus::Released, $request->promoCodeUsages()->sole()->status);
    }
}
