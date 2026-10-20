<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\FcmTopicSubscriptionRequest;
use App\Services\Notifications\FcmTopicService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class FcmTopicController extends Controller
{
    use ApiResponse;

    public function __construct(private FcmTopicService $topicService) {}

    /**
     * Subscribe a device token (or all active tokens of the authenticated user) to a topic.
     */
    public function subscribe(FcmTopicSubscriptionRequest $request): JsonResponse
    {
        $topic = $request->validated('topic');
        $explicitToken = $request->validated('token');

        if ($explicitToken !== null) {
            $tokens = [$explicitToken];
        } else {
            $tokens = $request->user()->fcmTokens()->where('is_active', true)->pluck('token')->all();
        }

        if (empty($tokens)) {
            return $this->errorResponse(__('notifications.no_active_tokens'), [], 400);
        }

        $this->topicService->subscribe($topic, $tokens);

        return $this->successResponse([
            'topic' => $topic,
            'tokens_count' => count($tokens),
        ], __('notifications.topic_subscribed'));
    }

    /**
     * Unsubscribe a device token (or all active tokens of the authenticated user) from a topic.
     */
    public function unsubscribe(FcmTopicSubscriptionRequest $request): JsonResponse
    {
        $topic = $request->validated('topic');
        $explicitToken = $request->validated('token');

        if ($explicitToken !== null) {
            $tokens = [$explicitToken];
        } else {
            $tokens = $request->user()->fcmTokens()->where('is_active', true)->pluck('token')->all();
        }

        if (empty($tokens)) {
            return $this->errorResponse(__('notifications.no_active_tokens'), [], 400);
        }

        $this->topicService->unsubscribe($topic, $tokens);

        return $this->successResponse([
            'topic' => $topic,
            'tokens_count' => count($tokens),
        ], __('notifications.topic_unsubscribed'));
    }
}
