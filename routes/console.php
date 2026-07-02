<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat: stamp the DB cache (+ a file) every minute so the Settings > Health
// tab can tell whether the scheduler cron is actually running. If this stops
// updating, the cron is down and campaigns will silently stop sending.
// No withoutOverlapping(): its cache lock could suppress the stamp and cause a
// false "never run".
Schedule::call(function () {
    \App\Support\CronHealth::stampHeartbeat();
})->everyMinute()->name('scheduler-heartbeat');

// Launch scheduled campaigns every minute.
Schedule::command('campaigns:dispatch-due')->everyMinute()->withoutOverlapping();

// Send due drip-sequence steps every minute.
Schedule::command('sequences:dispatch')->everyMinute()->withoutOverlapping();

// Drain the queued messages. Campaign sends are pushed to the (database) queue and
// need a worker to actually deliver them — without this the campaign sits on
// "sending" forever. Driving the worker from the scheduler means a single cron
// (`schedule:run`) runs everything on shared hosting. --max-time keeps each run
// under a minute (so the next cron tick restarts it cleanly); --stop-when-empty
// exits early once the queue drains; withoutOverlapping stops two workers stacking.
// Kept in the FOREGROUND on purpose: background scheduled processes are blocked on
// some hosts, which would silently leave the queue unworked — the exact bug we fix.
Schedule::command('queue:work --max-time=55 --sleep=1 --tries=2 --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping();
