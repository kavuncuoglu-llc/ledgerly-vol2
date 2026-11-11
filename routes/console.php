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
Schedule::command('ai:spend-breaker')->everyMinute()->withoutOverlapping();                // Chapter 36: spend over the last fifteen minutes against what is normal for this hour
Schedule::command('ai:reconcile')->dailyAt('04:00');                                         // the ledger against the provider's own figures, and the counters rebuilt from the ledger
Schedule::command('ai:showback --send')->weeklyOn(1, '07:00');                               // each team's spend, Mondays
Schedule::command('ai:ledger-consume --once')->everyMinute()->withoutOverlapping();         // a safety net behind the long-running consumer: nothing waits on the stream for more than a minute
Schedule::job(new ComputeOnlineSignals)->dailyAt('02:30');     // Chapter 30: yesterday's re-asks, follow-up tickets, escalations and resolutions
