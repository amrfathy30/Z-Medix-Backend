<?php

namespace App\Console\Commands\Notifications;

use App\Services\Notifications\FcmTopicService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Unsubscribes a device token from an FCM topic.
 */
class UnsubscribeFcmTopicCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fcm:topic:unsubscribe {topic} {token}';

    /**
     * @var string
     */
    protected $description = 'Unsubscribe a device token from a Firebase Cloud Messaging topic';

    public function handle(FcmTopicService $topicService): int
    {
        $topic = (string) $this->argument('topic');
        $token = (string) $this->argument('token');

        try {
            $result = $topicService->unsubscribe($topic, $token);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Unsubscribed token from topic [{$topic}]. Result: ".json_encode($result));

        return self::SUCCESS;
    }
}
