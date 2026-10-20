<?php

namespace App\Console\Commands\Notifications;

use Illuminate\Console\Command;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Throwable;

/**
 * Sends one push directly to a device token.
 */
class TestFcmCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fcm:test {token} {--title=Test notification} {--body=This is a test push from Z-Medix.}';

    /**
     * @var string
     */
    protected $description = 'Send a one-off Firebase Cloud Messaging push to a device token';

    public function handle(Messaging $messaging): int
    {
        $message = CloudMessage::new()
            ->withToken($this->argument('token'))
            ->withNotification(Notification::create($this->option('title'), $this->option('body')))
            ->withData(['category' => 'test', 'sent_at' => now()->toIso8601String()]);

        try {
            $result = $messaging->send($message);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Sent. Message: '.($result['name'] ?? json_encode($result)));

        return self::SUCCESS;
    }
}
