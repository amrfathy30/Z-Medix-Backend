<?php

namespace App\Console\Commands\Notifications;

use App\Services\Notifications\FcmTopicService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Subscribes a device token to an FCM topic.
 */
class SubscribeFcmTopicCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fcm:topic:subscribe {topic} {token}';

    /**
     * @var string
     */
    protected $description = 'Subscribe a device token to a Firebase Cloud Messaging topic';

    public function handle(FcmTopicService $topicService): int
    {
        $topic = (string) $this->argument('topic');
        $token = (string) $this->argument('token');

        try {
            $result = $topicService->subscribe($topic, $token);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Subscribed token to topic [{$topic}]. Result: ".json_encode($result));

        return self::SUCCESS;
    }
}
