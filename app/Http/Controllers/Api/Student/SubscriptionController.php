<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\CurrentSubscriptionResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the student stands: their plan, how long it lasts, and the features it
 * gives them. Open to every authenticated student.
 *
 * `features` is only ever the current plan's own features, and is empty without
 * an active subscription, so an app that renders from this list can never show a
 * feature the student cannot use.
 */
class SubscriptionController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(new CurrentSubscriptionResource($request->user()));
    }
}
