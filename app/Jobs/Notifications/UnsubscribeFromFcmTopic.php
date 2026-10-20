<?php

namespace App\Jobs\Notifications;

use App\Services\Notifications\FcmTopicService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Unsubscribes registration tokens from an FCM topic asynchronously.
 */
class UnsubscribeFromFcmTopic implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  string|array<string>  $tokens
     */
    public function __construct(
        public string $topic,
        public string|array $tokens,
    ) {}

    public function handle(FcmTopicService $topicService): void
    {
        $topicService->unsubscribe($this->topic, $this->tokens);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('FCM topic unsubscription failed in job', [
            'topic' => $this->topic,
            'exception' => $exception->getMessage(),
        ]);
    }
}
