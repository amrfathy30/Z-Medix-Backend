<?php

use App\Enums\PlanFeature;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\Subscription\FeatureAccessService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('student.ai.conversation.{conversationId}', function (User $user, string $conversationId): bool {
    return app(FeatureAccessService::class)->hasFeature($user, PlanFeature::Ai)
        && AiConversation::query()->where('user_id', $user->id)->whereKey($conversationId)->exists();
}, ['guards' => ['sanctum']]);
