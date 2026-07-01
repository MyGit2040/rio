<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Quiet-hours guard. Given a tenant's settings, decides whether the current
 * moment is inside the "do not send" window and, if so, the next allowed time.
 *
 * A compliant courtesy feature — it only DELAYS sends into daytime hours,
 * it never changes message content or fakes anything.
 */
class SendingWindow
{
    /**
     * Return the next time sending is allowed, or null if it's allowed right now.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public static function nextAllowed(?array $settings): ?Carbon
    {
        if (empty($settings['quiet_hours_enabled'])) {
            return null;
        }

        $start = (string) ($settings['quiet_start'] ?? '');
        $end = (string) ($settings['quiet_end'] ?? '');

        if (! preg_match('/^\d{1,2}:\d{2}$/', $start) || ! preg_match('/^\d{1,2}:\d{2}$/', $end) || $start === $end) {
            return null;
        }

        $tz = $settings['quiet_timezone'] ?? config('app.timezone', 'UTC');
        $now = Carbon::now($tz);

        $startAt = $now->copy()->setTimeFromTimeString($start)->startOfMinute();
        $endAt = $now->copy()->setTimeFromTimeString($end)->startOfMinute();

        // Overnight window (e.g. 21:00 → 08:00): quiet if after start OR before end.
        if ($startAt->greaterThan($endAt)) {
            if ($now->greaterThanOrEqualTo($startAt)) {
                return $endAt->addDay();          // tonight → tomorrow morning
            }
            if ($now->lessThan($endAt)) {
                return $endAt;                    // early morning → this morning's end
            }

            return null;                          // daytime — allowed
        }

        // Same-day window (e.g. 12:00 → 14:00).
        if ($now->greaterThanOrEqualTo($startAt) && $now->lessThan($endAt)) {
            return $endAt;
        }

        return null;
    }
}
