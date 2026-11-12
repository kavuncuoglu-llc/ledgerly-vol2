<?php

use App\Ai\Review\QuarterlyReview;
use App\Jobs\ComputeOnlineSignals;
use App\Notifications\QuarterlyReviewReady;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
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
Schedule::command('ai:cache-share --notify')->hourly();                                      // Chapter 37: a feature whose cached share fell under its floor tells its team
Schedule::command('ai:guardrails support.reply')->everyFiveMinutes()->withoutOverlapping();     // Chapter 44: a canary or a fresh promotion is held to the live scorecard
Schedule::command('registry:retire')->dailyAt('05:00');                                          // Chapter 44: previous versions whose warm period has ended are unloaded and archived
Schedule::command('ai:ft:poll')->everyFiveMinutes()->withoutOverlapping();                    // Chapter 41: hosted fine-tuning jobs, until they end
Schedule::command('ai:batch:poll --collect')->everyTenMinutes();                          // Chapter 37: batches submitted for work nobody is waiting on
Schedule::job(new ComputeOnlineSignals)->dailyAt('02:30');     // Chapter 30: yesterday's re-asks, follow-up tickets, escalations and resolutions
Schedule::command('ai:deprecations --notify')->dailyAt('06:00');                                  // Chapter 50: alerts at 90, 30 and 7 days before anything with a date
Schedule::command('ai:quarterly-review')                                                                      // Chapter 50: opened on the first working day of each quarter and assigned to the feature's owner
    ->cron('0 9 * 1,4,7,10 1-5')
    ->when(fn () => QuarterlyReview::isFirstWorkingDayOfQuarter(CarbonImmutable::now()))
    ->onSuccess(fn () => Notification::route('team', config('ai.alerts.channel'))->notify(new QuarterlyReviewReady(QuarterlyReview::quarterOf(CarbonImmutable::now()), (string) config('ai-watch.owner'))));
if (config('fairness.nightly')) {
    Schedule::command('eval:fairness --base=golden:fairness-150 --dimensions=name,region,english,language,tier --runs=3')->dailyAt('01:30')->withoutOverlapping();       // Chapter 51: nightly, and on the scorecard
}
