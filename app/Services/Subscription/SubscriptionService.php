<?php

namespace App\Services\Subscription;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionRequestStatus;
use App\Events\SubscriptionApproved;
use App\Events\SubscriptionRejected;
use App\Exceptions\Subscription\SubscriptionException;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\StudentSubscription;
use App\Models\SubscriptionPeriod;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Services\Media\MediaUploadService;
use Illuminate\Support\Facades\DB;

/**
 * The writes of the manual-payment flow: a student asks for a plan, the admin
 * takes payment on WhatsApp and records a period, which is what activates (or
 * renews) the subscription.
 *
 * Reads of "what may this student do right now" live in
 * {@see FeatureAccessService}.
 */
class SubscriptionService
{
    public function __construct(private MediaUploadService $media, private PromoCodeService $promos) {}

    /**
     * Records a student's request for a plan.
     *
     * A student has at most one pending request: asking again while one is open
     * changes that request instead of stacking a second one, so the admin's
     * inbox never holds two contradicting asks from the same person. The
     * returned model's `wasRecentlyCreated` tells the two cases apart.
     *
     * @throws SubscriptionException
     */
    public function submitRequest(User $student, Plan $plan, BillingCycle $cycle, ?string $promoCode = null): SubscriptionRequest
    {
        return DB::transaction(function () use ($student, $plan, $cycle, $promoCode): SubscriptionRequest {
            User::query()->lockForUpdate()->findOrFail($student->getKey());
            $pending = SubscriptionRequest::query()
                ->where('user_id', $student->getKey())
                ->pending()->orderByDesc('id')->lockForUpdate()->first();
            $plan = Plan::withTrashed()->lockForUpdate()->findOrFail($plan->getKey());
            $this->promos->validatePlan($plan, $cycle);
            $code = filled($promoCode) ? PromoCode::normalize($promoCode) : null;

            if ($code === null && $pending !== null && $pending->plan_id === $plan->getKey()
                && $pending->billing_cycle === $cycle && $pending->pricing_snapshot !== null
                && ($pending->pricing_snapshot['code'] ?? null) === $code) {
                return $pending;
            }

            $pricing = $code === null
                ? ['code' => null] + $this->promos->pricing($plan, $cycle)
                : $this->promos->validate($student, $plan, $cycle, $code, lock: true);
            unset($pricing['valid']);

            if ($pending !== null) {
                $this->transitionPromoUsage($pending, 'released');
            }

            $attributes = [
                'user_id' => $student->getKey(),
                'plan_id' => $plan->getKey(),
                'billing_cycle' => $cycle,
                'pricing_snapshot' => $pricing,
            ];
            if ($pending !== null) {
                $pending->forceFill($attributes)->save();
                $request = $pending->refresh();
            } else {
                $request = SubscriptionRequest::query()->create($attributes);
            }

            if ($code !== null) {
                $promo = PromoCode::query()->where('code', $code)->firstOrFail();
                $request->promoCodeUsages()->create($pricing + [
                    'promo_code_id' => $promo->getKey(),
                    'user_id' => $student->getKey(),
                    'plan_name' => $plan->name,
                    'billing_cycle' => $cycle->value,
                ]);
            }

            return $request;
        }, attempts: 5);
    }

    private function transitionPromoUsage(SubscriptionRequest $request, string $status): void
    {
        $usages = $request->promoCodeUsages()->where('status', 'reserved')->lockForUpdate()->get();
        foreach ($usages as $usage) {
            PromoCode::withTrashed()->whereKey($usage->promo_code_id)->lockForUpdate()->firstOrFail();
            $usage->update(['status' => $status]);
        }
    }

    /**
     * Turns a payment the admin took into a period, creating the student's
     * subscription the first time and extending it afterwards.
     *
     * Every payment starts a period immediately at the server approval time.
     * The latest period decides the plan permanently; older periods remain
     * unchanged as history and never resume granting access.
     *
     * @throws SubscriptionException
     */
    public function recordPeriod(RecordedPayment $payment): SubscriptionPeriod
    {
        $request = $payment->request;

        if ($request !== null) {
            if ($request->user_id !== $payment->student->getKey()) {
                throw SubscriptionException::requestNotOwned();
            }
        }

        $period = DB::transaction(function () use ($payment, $request): SubscriptionPeriod {
            User::query()->lockForUpdate()->findOrFail($payment->student->getKey());
            if ($request !== null) {
                // Re-read under a lock so two admins approving the same request
                // cannot both record a payment for it.
                $request = SubscriptionRequest::query()->lockForUpdate()->findOrFail($request->getKey());

                if (! $request->isPending()) {
                    throw SubscriptionException::requestNotPending();
                }
            }

            /** @var StudentSubscription $subscription */
            $subscription = StudentSubscription::query()
                ->where('user_id', $payment->student->getKey())
                ->lockForUpdate()
                ->first()
                ?? StudentSubscription::query()->create(['user_id' => $payment->student->getKey()]);

            $startsAt = now();
            $paidAt = $payment->paidAt ?? $startsAt;

            /** @var SubscriptionPeriod $period */
            $period = $subscription->periods()->create([
                'plan_id' => $payment->plan->getKey(),
                'subscription_request_id' => $request?->getKey(),
                'billing_cycle' => $payment->cycle,
                'starts_at' => $startsAt,
                'ends_at' => $payment->cycle->endsAt($startsAt),
                'amount' => $payment->amount,
                'currency' => $payment->currency ?? $payment->plan->currency,
                'paid_at' => $paidAt,
                'notes' => $payment->notes,
                'recorded_by' => $payment->recordedBy?->getKey(),
            ]);

            $this->media->upload($period, $payment->proof, SubscriptionPeriod::PROOF_COLLECTION);

            // A payment re-activates a subscription that was cancelled.
            if ($subscription->isCancelled()) {
                $subscription->forceFill(['cancelled_at' => null, 'cancellation_reason' => null])->save();
            }

            if ($request !== null) {
                $request->forceFill([
                    'status' => SubscriptionRequestStatus::Approved,
                    'handled_by' => $payment->recordedBy?->getKey(),
                    'handled_at' => $startsAt,
                ])->save();
                $this->transitionPromoUsage($request, 'consumed');
            }

            return $period;
        }, attempts: 5);

        event(new SubscriptionApproved($payment->student, $payment->plan, $period));

        return $period;
    }

    /**
     * @throws SubscriptionException
     */
    public function rejectRequest(SubscriptionRequest $request, ?Admin $admin = null, ?string $note = null): SubscriptionRequest
    {
        $updatedRequest = DB::transaction(function () use ($request, $admin, $note): SubscriptionRequest {
            User::query()->lockForUpdate()->findOrFail($request->user_id);
            $request = SubscriptionRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if (! $request->isPending()) {
                throw SubscriptionException::requestNotPending();
            }

            $request->forceFill([
                'status' => SubscriptionRequestStatus::Rejected,
                'admin_note' => $note,
                'handled_by' => $admin?->getKey(),
                'handled_at' => now(),
            ])->save();

            $this->transitionPromoUsage($request, 'released');

            return $request;
        }, attempts: 5);

        $updatedRequest->loadMissing(['user', 'plan']);
        if ($updatedRequest->user !== null && $updatedRequest->plan !== null) {
            event(new SubscriptionRejected($updatedRequest->user, $updatedRequest->plan, $note));
        }

        return $updatedRequest;
    }

    /**
     * Withdraws access immediately, even inside a paid period. Any refund is
     * settled outside the app.
     */
    public function cancel(StudentSubscription $subscription, ?string $reason = null): StudentSubscription
    {
        $subscription->forceFill([
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ])->save();

        return $subscription;
    }

    public function reinstate(StudentSubscription $subscription): StudentSubscription
    {
        $subscription->forceFill([
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ])->save();

        return $subscription;
    }
}
