<?php

namespace App\Http\Controllers\Api\Student;

use App\Events\StudentSubscribed;
use App\Exceptions\Subscription\SubscriptionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\StoreSubscriptionRequestRequest;
use App\Http\Resources\Api\Student\SubscriptionRequestResource;
use App\Services\Subscription\SubscriptionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use App\Support\Subscription\SubscriptionContact;
use Illuminate\Http\JsonResponse;

/**
 * A student asking for a plan. Nothing is charged here: payment happens on
 * WhatsApp, and the admin activates the plan afterwards.
 */
class SubscriptionRequestController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(private SubscriptionService $subscriptions) {}

    /**
     * 201 for a new request; 200 when the student already had one pending and
     * it was changed to this plan and cycle.
     */
    public function store(StoreSubscriptionRequestRequest $request): JsonResponse
    {
        $plan = $request->plan();
        $cycle = $request->cycle();

        try {
            $subscriptionRequest = $this->subscriptions->submitRequest($request->user(), $plan, $cycle);
        } catch (SubscriptionException $exception) {
            return $this->studyErrorResponse($exception);
        }

        event(new StudentSubscribed($request->user(), $plan->name));

        $subscriptionRequest->load('plan');

        $message = __('subscriptions.whatsapp_message', [
            'plan' => $plan->name,
            'cycle' => $cycle->label(),
        ]);

        return $this->successResponse(
            [
                'request' => new SubscriptionRequestResource($subscriptionRequest),
                'contact' => SubscriptionContact::toArray($message),
            ],
            'Your request was sent. We will contact you on WhatsApp to complete the payment.',
            $subscriptionRequest->wasRecentlyCreated ? 201 : 200,
        );
    }
}
