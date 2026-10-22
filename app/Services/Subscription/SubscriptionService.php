<?php

namespace App\Services\Subscription;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionRequestStatus;
use App\Events\SubscriptionApproved;
use App\Events\SubscriptionRejected;
use App\Exceptions\Subscription\SubscriptionException;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\StudentSubscription;
use App\Models\SubscriptionPeriod;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Services\Media\MediaUploadService;
use Carbon\CarbonInterface;
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
    public function __construct(private MediaUploadService $media) {}

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
    public function submitRequest(User $student, Plan $plan, BillingCycle $cycle): SubscriptionRequest
    {
        if (! $plan->is_active || $plan->trashed()) {
            throw SubscriptionException::planUnavailable();
        }

        if (! $plan->offers($cycle)) {
            throw SubscriptionException::cycleNotOffered();
        }

        return DB::transaction(function () use ($student, $plan, $cycle): SubscriptionRequest {
            /** @var SubscriptionRequest|null $pending */
            $pending = SubscriptionRequest::query()
                ->where('user_id', $student->getKey())
                ->pending()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($pending !== null) {
                $pending->forceFill([
                    'plan_id' => $plan->getKey(),
                    'billing_cycle' => $cycle,
                ])->save();

                return $pending->refresh();
            }

            return SubscriptionRequest::query()->create([
                'user_id' => $student->getKey(),
                'plan_id' => $plan->getKey(),
                'billing_cycle' => $cycle,
            ]);
        });
    }

    /**
     * Turns a payment the admin took into a period, creating the student's
     * subscription the first time and extending it afterwards.
     *
     * A renewal continues from where the student's coverage ends (or from now if
     * it has lapsed); the admin can override the start, for example to start an
     * upgrade immediately. Overlapping periods are allowed: the one that started
     * last decides the plan.
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

        return DB::transaction(function () use ($payment, $request): SubscriptionPeriod {
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

            $startsAt = $payment->startsAt ?? $this->defaultStart($subscription);
            $paidAt = $payment->paidAt ?? now();

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
                    'handled_at' => now(),
                ])->save();
            }

            return $period;
        });

        event(new SubscriptionApproved($payment->student, $payment->plan, $period));

        return $period;
    }

    /**
     * @throws SubscriptionException
     */
    public function rejectRequest(SubscriptionRequest $request, ?Admin $admin = null, ?string $note = null): SubscriptionRequest
    {
        $updatedRequest = DB::transaction(function () use ($request, $admin, $note): SubscriptionRequest {
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

            return $request;
        });

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

    /**
     * Where a new period begins when the admin does not say: straight after the
     * coverage the student already has, or now if there is none left.
     */
    private function defaultStart(StudentSubscription $subscription): CarbonInterface
    {
        $latestEnd = $subscription->latestEndsAt();

        return $latestEnd !== null && $latestEnd->isFuture() ? $latestEnd : now();
    }
}
