<?php

namespace App\Support\Subscription;

use App\Models\Setting;

/**
 * Where a student goes to pay: the platform's WhatsApp number, kept in the
 * `whatsapp_number` site setting so the admin can change it without a deploy.
 */
final class SubscriptionContact
{
    public const SETTING_KEY = 'whatsapp_number';

    /**
     * The number exactly as the admin typed it, or null when none is set.
     */
    public static function number(): ?string
    {
        $value = Setting::query()->where('key', self::SETTING_KEY)->value('value');

        return filled($value) ? trim((string) $value) : null;
    }

    /**
     * A `wa.me` link that opens a chat with the platform, optionally with the
     * message already typed. Null when no number is configured.
     */
    public static function whatsappUrl(?string $message = null): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) self::number());

        if ($digits === '' || $digits === null) {
            return null;
        }

        $url = 'https://wa.me/'.$digits;

        return filled($message) ? $url.'?text='.rawurlencode($message) : $url;
    }

    /**
     * @return array{whatsapp_number: string|null, whatsapp_url: string|null}
     */
    public static function toArray(?string $message = null): array
    {
        return [
            'whatsapp_number' => self::number(),
            'whatsapp_url' => self::whatsappUrl($message),
        ];
    }
}
