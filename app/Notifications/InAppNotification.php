<?php

namespace App\Notifications;

use App\Models\Admin;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Generic in-app (database) and FCM notification.
 */
class InAppNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string|int>  $replace
     */
    public function __construct(
        public string $category,
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
        public array $extra = [],
        public ?string $titleKey = null,
        public ?string $bodyKey = null,
        public array $replace = [],
        public ?string $type = null,
    ) {}

    /**
     * @return array<int, class-string|string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_merge([
            'category' => $this->category,
            'type' => $this->type ?? $this->category,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
            ...($this->titleKey !== null ? ['title_key' => $this->titleKey] : []),
            ...($this->bodyKey !== null ? ['body_key' => $this->bodyKey] : []),
            ...($this->replace !== [] ? ['replace' => $this->replace] : []),
            ...($notifiable instanceof Admin ? ['format' => 'filament', 'duration' => 'persistent'] : []),
        ], $this->extra);
    }
}
