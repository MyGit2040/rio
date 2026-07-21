<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Formats stored timestamps in the workspace's own timezone.
 *
 * Everything is stored in UTC (config('app.timezone') stays UTC so scheduling,
 * queue delays and quiet hours have one stable reference). For display we
 * convert to the tenant's timezone — the `timezone` setting, falling back to the
 * older `quiet_timezone`, then UTC. Resolved per call (never cached statically):
 * LiteSpeed reuses a PHP worker across different users' requests, so a static
 * cache would leak one workspace's timezone into the next.
 */
class LocalTime
{
    public static function zone(): string
    {
        $s = (array) (auth()->user()?->tenant?->settings ?? []);

        return ($s['timezone'] ?? $s['quiet_timezone'] ?? config('app.timezone', 'UTC')) ?: 'UTC';
    }

    /**
     * Convert a stored (UTC) datetime into the workspace timezone and format it.
     * A null/empty value renders as $empty so callers can drop their own `?? '—'`.
     */
    public static function format($value, string $format = 'M j, Y g:i A', string $empty = '—'): string
    {
        if (empty($value)) {
            return $empty;
        }

        return Carbon::parse($value)->timezone(self::zone())->format($format);
    }

    /**
     * Interpret a datetime-local string (as typed in the picker, i.e. in the
     * workspace timezone) as a real instant.
     */
    public static function parseInput($value): Carbon
    {
        return Carbon::parse($value, self::zone());
    }

    /**
     * Validation closure: a datetime-local string must be in the future when read
     * in the workspace timezone. `date`/`after:now` can't do this — they read the
     * bare string as app-time (UTC), so a local time near the edge validated wrong.
     */
    public static function futureRule(string $message = 'The send time must be in the future.'): \Closure
    {
        return function ($attribute, $value, $fail) use ($message) {
            if ($value && self::parseInput($value)->isPast()) {
                $fail($message);
            }
        };
    }
}
