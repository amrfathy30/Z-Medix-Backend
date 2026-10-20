<?php

namespace App\Services\Subscription;

use App\Enums\BillingCycle;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\SubscriptionRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;

/**
 * What the admin recorded after taking a payment on WhatsApp: everything needed
 * to turn it into a subscription period.
 */
final class RecordedPayment
{
    /**
     * @param  UploadedFile|string  $proof  The payment screenshot: an upload, or an absolute path to a file the
     *                                      media library may take over.
     * @param  string|null  $currency  Defaults to the plan's currency.
     * @param  CarbonInterface|null  $startsAt  Defaults to where the student's current coverage ends, or now.
     */
    public function __construct(
        public readonly User $student,
        public readonly Plan $plan,
        public readonly BillingCycle $cycle,
        public readonly string|float $amount,
        public readonly UploadedFile|string $proof,
        public readonly ?CarbonInterface $paidAt = null,
        public readonly ?CarbonInterface $startsAt = null,
        public readonly ?string $currency = null,
        public readonly ?string $notes = null,
        public readonly ?Admin $recordedBy = null,
        public readonly ?SubscriptionRequest $request = null,
    ) {}
}
