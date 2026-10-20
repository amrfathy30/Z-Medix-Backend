<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\PlanResource;
use App\Models\Plan;
use App\Services\Subscription\FeatureAccessService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The plans a student can choose from. Open to every authenticated student,
 * subscribed or not: it is how someone without a plan finds one.
 */
class PlanController extends Controller
{
    use ApiResponse;

    public function __construct(private FeatureAccessService $access) {}

    /**
     * Active plans in the admin's order, with the student's current one marked.
     */
    public function index(Request $request): JsonResponse
    {
        $currentPlanId = $this->access->currentPeriod($request->user())?->plan_id;

        $plans = Plan::query()
            ->active()
            ->ordered()
            ->with('featureLimits')
            ->get()
            ->map(fn (Plan $plan): PlanResource => new PlanResource($plan, $currentPlanId));

        return $this->successResponse($plans);
    }
}
