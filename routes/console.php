<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reminder waves run hourly; the command is idempotent so the cadence is flexible.
Schedule::command('events:send-reminders')->hourly()->withoutOverlapping();
