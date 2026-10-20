<?php

namespace App\Jobs\Notifications;

use App\Models\FcmToken;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Throwable;

/**
 * Sends one push message to one device token via Firebase Cloud Messaging.
 */
class SendFcmMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public int $fcmTokenId,
        public string $title,
        public string $body,
        public array $data,
    ) {}

    public function handle(Messaging $messaging): void
    {
        $token = FcmToken::find($this->fcmTokenId);

        if ($token === null || ! $token->is_active) {
            return;
        }

        $message = CloudMessage::new()
            ->withToken($token->token)
            ->withNotification(FcmNotification::create($this->title, $this->body))
            ->withData($this->data);

        try {
            $messaging->send($message);
        } catch (NotFound|InvalidArgument $e) {
            $token->update(['is_active' => false]);
            Log::info('FCM token invalid, deactivated', [
                'fcm_token_id' => $this->fcmTokenId,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $token->update(['last_used_at' => now()]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('FCM send failed', [
            'fcm_token_id' => $this->fcmTokenId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
