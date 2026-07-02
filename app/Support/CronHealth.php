<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only diagnostics for the background engine that actually delivers
 * campaigns: the scheduler cron (`schedule:run`) and the queue worker it drives.
 *
 * When either stops, campaigns silently sit on "sending" forever — the exact
 * failure we hit on rio. This surfaces that as a warning/error/critical on the
 * Settings > Health tab instead of leaving it invisible.
 */
class CronHealth
{
    /** Heartbeat file the scheduler stamps every minute (see routes/console.php). */
    public static function heartbeatFile(): string
    {
        return storage_path('framework/scheduler-heartbeat');
    }

    /** When the scheduler last ran, or null if it never has. */
    public static function lastRun(): ?Carbon
    {
        $file = self::heartbeatFile();

        if (! is_file($file)) {
            return null;
        }

        $stamp = trim((string) @file_get_contents($file));

        try {
            return $stamp !== '' ? Carbon::parse($stamp) : Carbon::createFromTimestamp(filemtime($file));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The single cron line the operator must install (drives everything).
     */
    public static function cronLine(): string
    {
        return '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1';
    }

    /**
     * All health checks, each: key, label, status (ok|warning|error|critical),
     * message, detail.
     *
     * @return array<int, array<string, string>>
     */
    public static function checks(): array
    {
        return array_filter([
            self::schedulerCheck(),
            self::queueCheck(),
            self::failedJobsCheck(),
        ]);
    }

    /**
     * Worst status across all checks — drives the tab's overall banner.
     */
    public static function overall(): string
    {
        $rank = ['ok' => 0, 'warning' => 1, 'error' => 2, 'critical' => 3];
        $worst = 'ok';

        foreach (self::checks() as $c) {
            if (($rank[$c['status']] ?? 0) > ($rank[$worst] ?? 0)) {
                $worst = $c['status'];
            }
        }

        return $worst;
    }

    /** Is the scheduler cron alive (heartbeat within the last ~2 minutes)? */
    private static function schedulerCheck(): array
    {
        $last = self::lastRun();

        if ($last === null) {
            return [
                'key'     => 'scheduler',
                'label'   => 'Scheduler cron',
                'status'  => 'critical',
                'message' => 'Never run — the cron is not installed.',
                'detail'  => 'Campaigns and sequences will never send until the cron below is added to the server.',
            ];
        }

        $agoSeconds = $last->diffInSeconds(now());
        $ago = $last->diffForHumans();

        if ($agoSeconds > 300) {
            return [
                'key'     => 'scheduler',
                'label'   => 'Scheduler cron',
                'status'  => 'critical',
                'message' => "Stopped — last ran {$ago}.",
                'detail'  => 'The cron has not fired for over 5 minutes. Campaign sends are frozen. Check the server crontab.',
            ];
        }

        if ($agoSeconds > 120) {
            return [
                'key'     => 'scheduler',
                'label'   => 'Scheduler cron',
                'status'  => 'warning',
                'message' => "Last ran {$ago}.",
                'detail'  => 'Expected every minute. A short delay can be normal on a busy server; if it grows, the cron may be failing.',
            ];
        }

        return [
            'key'     => 'scheduler',
            'label'   => 'Scheduler cron',
            'status'  => 'ok',
            'message' => "Running — last ran {$ago}.",
            'detail'  => 'The per-minute cron is firing normally.',
        ];
    }

    /** Is the queue draining, or are due jobs piling up (worker down)? */
    private static function queueCheck(): array
    {
        $now = now()->getTimestamp();

        try {
            $total = (int) DB::table('jobs')->count();
            // A job is DUE when its (possibly delayed) available_at has passed and no
            // worker has reserved it. Delayed campaign sends (future available_at) are
            // NORMAL spacing — they must not count as a backlog.
            $dueQuery = DB::table('jobs')
                ->where('available_at', '<=', $now)
                ->whereNull('reserved_at');
            $due = (int) $dueQuery->count();
            $oldestDue = $dueQuery->min('available_at');
        } catch (Throwable $e) {
            return [
                'key'     => 'queue',
                'label'   => 'Queue worker',
                'status'  => 'warning',
                'message' => 'Could not read the queue.',
                'detail'  => $e->getMessage(),
            ];
        }

        if ($total === 0) {
            return [
                'key'     => 'queue',
                'label'   => 'Queue worker',
                'status'  => 'ok',
                'message' => 'Idle — no jobs waiting.',
                'detail'  => 'All queued messages have been delivered. The worker is driven by the scheduler cron.',
            ];
        }

        // Some jobs are queued but none are due yet → all delayed (spaced sends). Healthy.
        if ($due === 0) {
            return [
                'key'     => 'queue',
                'label'   => 'Queue worker',
                'status'  => 'ok',
                'message' => "Scheduled — {$total} job(s) queued for later.",
                'detail'  => 'Jobs are delayed for spaced delivery; none is overdue. The worker will pick each up at its send time.',
            ];
        }

        $oldestAgo = $oldestDue ? ($now - (int) $oldestDue) : 0;
        $oldestHuman = $oldestDue ? Carbon::createFromTimestamp((int) $oldestDue)->diffForHumans() : 'just now';

        // A job that has been due for minutes means nothing is draining the queue.
        if ($oldestAgo > 180) {
            return [
                'key'     => 'queue',
                'label'   => 'Queue worker',
                'status'  => 'critical',
                'message' => "Backing up — {$due} job(s) overdue, oldest due {$oldestHuman}.",
                'detail'  => 'Jobs are due but nothing is delivering them. The queue worker (or the scheduler that drives it) is not running.',
            ];
        }

        return [
            'key'     => 'queue',
            'label'   => 'Queue worker',
            'status'  => 'ok',
            'message' => "Working — {$due} due, {$total} total queued.",
            'detail'  => 'Due jobs are within the normal pickup window; the worker is draining the queue.',
        ];
    }

    /** Any permanently-failed jobs to review? */
    private static function failedJobsCheck(): ?array
    {
        try {
            $failed = (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return null; // table absent — nothing to report
        }

        if ($failed === 0) {
            return [
                'key'     => 'failed',
                'label'   => 'Failed jobs',
                'status'  => 'ok',
                'message' => 'None.',
                'detail'  => 'No jobs have failed permanently.',
            ];
        }

        return [
            'key'     => 'failed',
            'label'   => 'Failed jobs',
            'status'  => 'warning',
            'message' => "{$failed} failed job(s).",
            'detail'  => 'Some jobs exhausted their retries. Review and re-queue with `php artisan queue:retry all`, or clear with `queue:flush`.',
        ];
    }

    /**
     * The scheduled tasks the app runs every minute (for display on the tab).
     *
     * @return array<int, array<string, string>>
     */
    public static function scheduledTasks(): array
    {
        return [
            ['command' => 'campaigns:dispatch-due', 'purpose' => 'Launch campaigns whose scheduled time has arrived', 'expression' => '* * * * *'],
            ['command' => 'sequences:dispatch', 'purpose' => 'Send due drip-sequence steps', 'expression' => '* * * * *'],
            ['command' => 'queue:work (--stop-when-empty)', 'purpose' => 'Deliver queued campaign messages', 'expression' => '* * * * *'],
        ];
    }
}
