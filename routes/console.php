<?php

use App\Jobs\ComputeOnlineSignals;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('conversations:retain')->daily();
Schedule::command('replies:sweep')->everyFiveMinutes();
Schedule::command('reminders:draft')->dailyAt('03:00');
Schedule::command('ai:drift --feature=support.reply')->daily();                      // Chapter 31: input, output and quality drift, once a day
Schedule::job(new ComputeOnlineSignals)->dailyAt('02:30');     // Chapter 30: yesterday's re-asks, follow-up tickets, escalations and resolutions
