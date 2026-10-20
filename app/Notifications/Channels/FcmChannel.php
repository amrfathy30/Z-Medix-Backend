<?php

namespace App\Notifications\Channels;

use App\Jobs\Notifications\SendFcmMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches a push message per active device token for the notifiable.
 *
 * Reuses the notification's own toArray($notifiable) payload (title/body/
 * category/action_url/extra) instead of requiring a second toFcm() method on
 * every notification class. The actual Firebase call happens in a queued job
 * (SendFcmMessage) so this channel only ever performs a fast token lookup and
 * job dispatch — never a network call — regardless of whether the caller's
 * listener is itself queued.
 *
 * Never throws: a Firebase failure must stay independent of transactions.
 */
class FcmChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notifiable, 'fcmTokens') || ! method_exists($notification, 'toArray')) {
            return;
        }

        try {
            $tokens = $notifiable->fcmTokens()->where('is_active', true)->get();

            if ($tokens->isEmpty()) {
                return;
            }

            $payload = $notification->toArray($notifiable);
            $title = (string) ($payload['title'] ?? '');
            $body = (string) ($payload['body'] ?? '');
            $data = $this->sanitizeData($payload);

            foreach ($tokens as $token) {
                SendFcmMessage::dispatch($token->id, $title, $body, $data);
            }
        } catch (Throwable $e) {
            Log::error('FCM channel dispatch failed', [
                'notifiable_type' => method_exists($notifiable, 'getMorphClass') ? $notifiable->getMorphClass() : get_class($notifiable),
                'notifiable_id' => method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Flattens the notification payload into FCM's required string=>string data shape.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function sanitizeData(array $payload): array
    {
        $data = [];

        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }

            $data[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return $data;
    }
}
