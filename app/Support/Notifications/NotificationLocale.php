<?php

namespace App\Support\Notifications;

use App\Models\Admin;

/**
 * Resolves the recipient's language fresh per recipient rather than from
 * the ambient request/queue-worker locale.
 */
final class NotificationLocale
{
    private const DEFAULT_LOCALE = 'en';

    public static function forNotifiable(object $notifiable): string
    {
        if ($notifiable instanceof Admin) {
            return $notifiable->locale ?? self::DEFAULT_LOCALE;
        }

        return $notifiable->locale ?? config('app.locale', self::DEFAULT_LOCALE);
    }
}
