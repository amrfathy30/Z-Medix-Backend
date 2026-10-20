<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Throwable;

class FcmTopicService
{
    public function __construct(private Messaging $messaging) {}

    /**
     * Subscribes one or more registration tokens to a topic.
     *
     * @param  string|array<string>  $tokens
     * @return array<string, mixed>
     */
    public function subscribe(string $topic, string|array $tokens): array
    {
        $tokens = (array) $tokens;

        if (empty($tokens)) {
            return ['success' => true, 'count' => 0];
        }

        try {
            $result = $this->messaging->subscribeToTopic($topic, $tokens);

            Log::info('Subscribed tokens to FCM topic', [
                'topic' => $topic,
                'tokens_count' => count($tokens),
                'result' => $result,
            ]);

            return is_array($result) ? $result : ['success' => true, 'raw' => $result];
        } catch (Throwable $e) {
            Log::error('Failed to subscribe tokens to FCM topic', [
                'topic' => $topic,
                'tokens_count' => count($tokens),
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Unsubscribes one or more registration tokens from a topic.
     *
     * @param  string|array<string>  $tokens
     * @return array<string, mixed>
     */
    public function unsubscribe(string $topic, string|array $tokens): array
    {
        $tokens = (array) $tokens;

        if (empty($tokens)) {
            return ['success' => true, 'count' => 0];
        }

        try {
            $result = $this->messaging->unsubscribeFromTopic($topic, $tokens);

            Log::info('Unsubscribed tokens from FCM topic', [
                'topic' => $topic,
                'tokens_count' => count($tokens),
                'result' => $result,
            ]);

            return is_array($result) ? $result : ['success' => true, 'raw' => $result];
        } catch (Throwable $e) {
            Log::error('Failed to unsubscribe tokens from FCM topic', [
                'topic' => $topic,
                'tokens_count' => count($tokens),
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sends a push notification message to a topic.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        $sanitizedData = $this->sanitizeData($data);

        $message = CloudMessage::new()
            ->withTopic($topic)
            ->withNotification(Notification::create($title, $body))
            ->withData($sanitizedData);

        try {
            $result = $this->messaging->send($message);

            Log::info('Sent message to FCM topic', [
                'topic' => $topic,
                'title' => $title,
                'message_id' => $result['name'] ?? null,
            ]);

            return is_array($result) ? $result : ['name' => (string) $result];
        } catch (Throwable $e) {
            Log::error('Failed to send message to FCM topic', [
                'topic' => $topic,
                'title' => $title,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Flattens data to FCM's required string=>string format.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function sanitizeData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $sanitized[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return $sanitized;
    }
}
