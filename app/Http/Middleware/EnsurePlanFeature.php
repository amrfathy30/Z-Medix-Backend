<?php

namespace App\Http\Middleware;

use App\Enums\PlanFeature;
use App\Exceptions\Subscription\SubscriptionException;
use App\Models\User;
use App\Services\Subscription\FeatureAccessService;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards a student route behind a plan feature: `feature:library`,
 * `feature:question_bank`, `feature:case_report`, `feature:ai`.
 *
 * Runs after `auth:sanctum`. The backend is the authority on this: a student
 * whose plan lacks the feature is refused here whatever the app chooses to show.
 */
class EnsurePlanFeature
{
    use ApiResponse;

    public function __construct(private FeatureAccessService $access) {}

    public function handle(Request $request, Closure $next, string $feature): Response|JsonResponse
    {
        $planFeature = PlanFeature::tryFrom($feature)
            ?? throw new InvalidArgumentException("Unknown plan feature [{$feature}] on a route.");

        $student = $request->user();

        if (! $student instanceof User) {
            return $this->errorResponse('Unauthenticated.', [], 401);
        }

        try {
            $this->access->assertHasFeature($student, $planFeature);
        } catch (SubscriptionException $exception) {
            return $this->errorResponse($exception->getMessage(), [], $exception->status, $exception->errorCode);
        }

        return $next($request);
    }
}
