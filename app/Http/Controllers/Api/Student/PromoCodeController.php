<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Subscription\SubscriptionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\ValidatePromoCodeRequest;
use App\Services\Subscription\PromoCodeService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;

class PromoCodeController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __invoke(ValidatePromoCodeRequest $request, PromoCodeService $promos): JsonResponse
    {
        try {
            $pricing = $promos->validate($request->user(), $request->plan(), $request->cycle(), $request->validated('promo_code'));
        } catch (SubscriptionException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse($pricing, 'Promo code applied successfully.');
    }
}
