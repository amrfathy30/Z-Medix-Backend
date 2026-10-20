<?php

namespace App\Console\Commands\Notifications;

use App\Services\Notifications\FcmTopicService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends one push directly to an FCM topic.
 */
class SendFcmTopicCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fcm:topic {topic} {--title=Topic notification} {--body=This is a test topic push from Z-Medix.}';

    /**
     * @var string
     */
    protected $description = 'Send a push notification to a Firebase Cloud Messaging topic';

    public function handle(FcmTopicService $topicService): int
    {
        $topic = (string) $this->argument('topic');
        $title = (string) $this->option('title');
        $body = (string) $this->option('body');

        try {
            $result = $topicService->sendToTopic(
                topic: $topic,
                title: $title,
                body: $body,
                data: ['category' => 'topic_test', 'sent_at' => now()->toIso8601String()],
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Sent to topic ['.$topic.']. Result: '.json_encode($result));

        return self::SUCCESS;
    }
}
