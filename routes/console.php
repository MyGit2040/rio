<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Launch scheduled campaigns every minute.
Schedule::command('campaigns:dispatch-due')->everyMinute()->withoutOverlapping();

// Send due drip-sequence steps every minute.
Schedule::command('sequences:dispatch')->everyMinute()->withoutOverlapping();
