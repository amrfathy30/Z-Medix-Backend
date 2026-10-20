<?php

namespace App\Jobs\Notifications;

use App\Services\Notifications\FcmTopicService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a push notification message to a Firebase topic.
 */
class SendFcmTopicMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $topic,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(FcmTopicService $topicService): void
    {
        $topicService->sendToTopic(
            topic: $this->topic,
            title: $this->title,
            body: $this->body,
            data: $this->data,
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('FCM topic send failed in job', [
            'topic' => $this->topic,
            'title' => $this->title,
            'exception' => $exception->getMessage(),
        ]);
    }
}
