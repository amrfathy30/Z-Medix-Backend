<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\AccountStatus;
use App\Enums\BillingCycle;
use App\Enums\SubscriptionRequestType;
use App\Models\Plan;
use App\Models\StudentSubscription;
use App\Models\SubscriptionPeriod;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Services\Subscription\RecordedPayment;
use App\Services\Subscription\SubscriptionService;
use Database\Factories\SubscriptionPeriodFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionRequestTypeTest extends TestCase
{
    use RefreshDatabase;

    public static function histories(): array
    {
        return [['none', 'new'], ['active', 'renewal'], ['expired', 'renewal'], ['cancelled', 'renewal'], ['requests', 'new']];
    }

    #[DataProvider('histories')]
    public function test_request_type_uses_paid_history_and_is_returned_everywhere(string $history, string $type): void
    {
        $student = User::factory()->create(['status' => AccountStatus::Active]);
        $plan = Plan::factory()->create();
        if (in_array($history, ['active', 'expired', 'cancelled'], true)) {
            $factory = StudentSubscription::factory()->for($student, 'user');
            $factory = $history === 'expired' ? $factory->expired($plan) : $factory->active($plan);
            if ($history === 'cancelled') {
                $factory = $factory->cancelled();
            }
            $factory->create();
        }
        if ($history === 'requests') {
            SubscriptionRequest::factory()->rejected()->for($student, 'user')->create();
            SubscriptionRequest::factory()->for($student, 'user')->create();
        }
        Sanctum::actingAs($student);
        $this->postJson('/api/student/subscription-requests', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'promo_code' => null,
            'request_type' => $type === 'new' ? 'renewal' : 'new',
        ])->assertSuccessful()->assertJsonPath('data.request.request_type', $type);
        $this->getJson('/api/student/subscription-requests')->assertOk()->assertJsonPath('data.0.request_type', $type);
        $this->getJson('/api/student/subscription')->assertOk()->assertJsonPath('data.pending_request.request_type', $type);
    }

    public function test_pending_request_is_reclassified_on_reuse_without_changing_pricing(): void
    {
        $student = User::factory()->create(['status' => AccountStatus::Active]);
        $plan = Plan::factory()->create();
        $service = app(SubscriptionService::class);
        $pending = $service->submitRequest($student, $plan, BillingCycle::Monthly);
        $snapshot = $pending->pricing_snapshot;
        StudentSubscription::factory()->for($student, 'user')->active($plan)->create();
        $reused = $service->submitRequest($student, $plan, BillingCycle::Monthly);
        $this->assertSame($pending->id, $reused->id);
        $this->assertSame(SubscriptionRequestType::Renewal, $reused->request_type);
        $this->assertSame($snapshot, $reused->pricing_snapshot);
        $this->assertDatabaseCount('subscription_requests', 1);
    }

    public function test_admin_payment_updates_open_request_without_handling_it(): void
    {
        Storage::fake('local');
        $student = User::factory()->create();
        $plan = Plan::factory()->create();
        $service = app(SubscriptionService::class);
        $pending = $service->submitRequest($student, $plan, BillingCycle::Monthly);
        $service->recordPeriod(new RecordedPayment($student, $plan, BillingCycle::Monthly, 10, SubscriptionPeriodFactory::fakeProof()));
        $this->assertSame(SubscriptionRequestType::Renewal, $pending->refresh()->request_type);
        $this->assertTrue($pending->isPending());
        $this->assertNull($pending->handled_at);
        $this->assertDatabaseCount('subscription_periods', 1);
    }

    public function test_backfill_uses_original_submission_time_and_excludes_own_payment(): void
    {
        $student = User::factory()->create();
        $subscription = StudentSubscription::factory()->for($student, 'user')->cancelled()->create();
        $first = SubscriptionRequest::factory()->for($student, 'user')->create([
            'status' => 'approved', 'created_at' => '2025-01-01 00:00:00',
        ]);
        SubscriptionPeriod::factory()->create([
            'student_subscription_id' => $subscription->id, 'subscription_request_id' => $first->id,
            'created_at' => '2025-01-02 00:00:00', 'paid_at' => '2025-01-02 00:00:00',
        ]);
        $renewal = SubscriptionRequest::factory()->rejected()->for($student, 'user')->create(['created_at' => '2025-02-01 00:00:00']);
        $before = SubscriptionRequest::factory()->rejected()->for($student, 'user')->create(['created_at' => '2024-12-01 00:00:00']);
        $tie = SubscriptionRequest::factory()->rejected()->for($student, 'user')->create(['created_at' => '2025-01-02 00:00:00']);
        $pending = SubscriptionRequest::factory()->for($student, 'user')->create(['created_at' => '2024-01-01 00:00:00']);
        $timestamps = DB::table('subscription_requests')->pluck('updated_at', 'id')->all();
        $migration = require glob(database_path('migrations/*backfill_subscription_request_types.php'))[0];
        $migration->up();
        $this->assertSame(SubscriptionRequestType::New, $first->refresh()->request_type);
        $this->assertSame('approved', $first->status->value);
        $this->assertSame(SubscriptionRequestType::Renewal, $renewal->refresh()->request_type);
        $this->assertSame('rejected', $renewal->status->value);
        $this->assertSame(SubscriptionRequestType::New, $before->refresh()->request_type);
        $this->assertSame(SubscriptionRequestType::New, $tie->refresh()->request_type);
        $this->assertSame(SubscriptionRequestType::Renewal, $pending->refresh()->request_type);
        $this->assertSame($timestamps, DB::table('subscription_requests')->pluck('updated_at', 'id')->all());
        $this->assertDatabaseCount('subscription_requests', 5);
    }

    public function test_column_migration_rolls_back_and_reapplies_without_losing_requests(): void
    {
        $request = SubscriptionRequest::factory()->rejected()->create();
        $migration = require glob(database_path('migrations/*add_request_type_to_subscription_requests_table.php'))[0];
        $migration->down();
        $migration->up();
        $this->assertSame(SubscriptionRequestType::New, $request->refresh()->request_type);
        $this->assertSame('rejected', $request->status->value);
    }
}
