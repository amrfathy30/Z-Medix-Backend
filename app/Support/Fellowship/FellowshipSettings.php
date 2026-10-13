<?php

namespace App\Support\Fellowship;

use App\Enums\SettingValueType;
use App\Models\Setting;

/**
 * The platform-wide Fellowship settings an admin edits.
 *
 * There is exactly one of these today: the advisory per-question time limit.
 * It is global rather than per-Fellowship by design, and it is snapshotted into
 * each session at generation — so changing it here affects sessions generated
 * from now on and never re-judges one already running.
 *
 * Stored as a `settings` row so it is editable without a deploy, and kept
 * `is_public = false` so it is not served by the public settings endpoint. The
 * `config/fellowship.php` value is the fallback until one has been saved.
 */
final class FellowshipSettings
{
    public const QUESTION_TIME_LIMIT_KEY = 'fellowship_question_time_limit_seconds';

    public const GROUP = 'fellowship';

    /**
     * The limit, in seconds, for one question. Always at least one second: a
     * zero or negative stored value would make every timed answer late.
     */
    public static function questionTimeLimitSeconds(): int
    {
        $stored = Setting::query()
            ->where('key', self::QUESTION_TIME_LIMIT_KEY)
            ->value('value');

        $seconds = is_numeric($stored)
            ? (int) $stored
            : (int) config('fellowship.question_time_limit_seconds', 90);

        return max(1, $seconds);
    }

    public static function setQuestionTimeLimitSeconds(int $seconds): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::QUESTION_TIME_LIMIT_KEY],
            [
                'value' => (string) max(1, $seconds),
                'value_type' => SettingValueType::Integer,
                'group' => self::GROUP,
                'is_public' => false,
                'description' => 'Advisory per-question time limit for Fellowship quiz sessions, in seconds.',
            ],
        );
    }
}
