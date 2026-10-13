<?php

namespace App\Support\Fellowship;

use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * The module's one answer to "which calendar day was that?".
 *
 * Instants are stored in UTC like everywhere else in the app. Day Streak, Daily
 * Study Time and the midnight split of an activity period are all about days as
 * the student experiences them, so every one of them converts through this class
 * and the single configured timezone behind it — rather than each query picking
 * its own, which is how a streak comes to disagree with the chart beside it.
 */
final class FellowshipClock
{
    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) config('fellowship.timezone', config('app.timezone', 'UTC')));
    }

    /**
     * The same instant, read in the configured timezone.
     */
    public static function local(DateTimeInterface|string $instant): Carbon
    {
        return Carbon::parse($instant)->setTimezone(self::timezone());
    }

    /**
     * The calendar date an instant falls on, as `Y-m-d`.
     */
    public static function dateOf(DateTimeInterface|string $instant): string
    {
        return self::local($instant)->toDateString();
    }

    public static function today(): Carbon
    {
        return Carbon::now(self::timezone())->startOfDay();
    }

    /**
     * Midnight at the start of the local day an instant falls on, as a UTC
     * instant — the boundary a period's duration is split on.
     */
    public static function startOfLocalDay(DateTimeInterface|string $instant): Carbon
    {
        return self::local($instant)->startOfDay()->utc();
    }

    /**
     * Midnight ending the local day an instant falls on, as a UTC instant.
     */
    public static function endOfLocalDay(DateTimeInterface|string $instant): Carbon
    {
        return self::local($instant)->startOfDay()->addDay()->utc();
    }

    /**
     * Split a span across the local calendar days it covers.
     *
     * A period a student left running from late evening into the small hours is
     * one period but two days of study time, and the chart has to show it that
     * way. Returns `Y-m-d => seconds`, in chronological order, and an empty
     * array for a span of no length.
     *
     * @return array<string, int>
     */
    public static function splitAcrossDays(DateTimeInterface|string $from, DateTimeInterface|string $to): array
    {
        $start = Carbon::parse($from)->utc();
        $end = Carbon::parse($to)->utc();

        if ($end->lessThanOrEqualTo($start)) {
            return [];
        }

        $buckets = [];
        $cursor = $start->copy();

        while ($cursor->lessThan($end)) {
            $boundary = self::endOfLocalDay($cursor);
            $segmentEnd = $boundary->lessThan($end) ? $boundary : $end;
            $date = self::dateOf($cursor);

            $buckets[$date] = ($buckets[$date] ?? 0) + max(0, (int) $cursor->diffInSeconds($segmentEnd, absolute: true));

            $cursor = $segmentEnd;
        }

        return $buckets;
    }
}
