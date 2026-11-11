<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('conversations:retain')->daily();
Schedule::command('replies:sweep')->everyFiveMinutes();
Schedule::command('reminders:draft')->dailyAt('03:00');
