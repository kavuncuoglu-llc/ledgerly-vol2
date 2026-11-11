<?php

use App\Ai\Budgets\Budget;
use App\Ai\Budgets\BudgetExhausted;
use App\Ai\Budgets\Ladder;
use App\Jobs\DraftReminder;
use App\Jobs\Middleware\PauseWhenBudgetSpent;
use App\Jobs\ShadowAnswer;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Queue;

// Chapter 36, Topic 5: a feature nobody is waiting on pauses when its budget is spent, and runs again when the period resets.
function exhausted(int $resetsIn = 5_400): BudgetExhausted
{
    return new BudgetExhausted(new Budget('{ai-budget}:feature:reminder.draft:day:2026-11-11', Budget::FEATURE_DAY, 'reminder.draft per day', 0, 3600, 'reminder.draft', $resetsIn), 'pause');
}

it('queues the job again for when the period resets, and deletes this attempt, without using one of its attempts', function () {
    Queue::fake();

    $job = new DraftReminder(41);

    $job->job = Mockery::mock(Job::class);

    $job->job->shouldReceive('delete')->once();

    $result = (new PauseWhenBudgetSpent)->handle($job, fn () => throw exhausted());

    expect($result)->toBeNull();

    Queue::assertPushed(DraftReminder::class, fn (DraftReminder $paused) => $paused->invoiceId === 41 && $paused->job === null && $paused->delay === 5_400);
});

it('waits at least a minute, even when the period is about to end', function () {
    Queue::fake();

    $job = new ShadowAnswer(9);

    $job->job = Mockery::mock(Job::class);

    $job->job->shouldReceive('delete')->once();

    (new PauseWhenBudgetSpent)->handle($job, fn () => throw exhausted(5));

    Queue::assertPushed(ShadowAnswer::class, fn ($paused) => $paused->delay === 60);
});

it('lets every other exception through, and a finished job alone', function () {
    $job = new DraftReminder(41);

    expect(fn () => (new PauseWhenBudgetSpent)->handle($job, fn () => throw new RuntimeException('x')))->toThrow(RuntimeException::class)
        ->and((new PauseWhenBudgetSpent)->handle($job, fn () => 'done'))->toBe('done');
});

it('is on the background jobs that call a model', function () {
    expect((new DraftReminder(1))->middleware())->toHaveCount(1)->and((new DraftReminder(1))->middleware()[0])->toBeInstanceOf(PauseWhenBudgetSpent::class)
        ->and((new ShadowAnswer(1))->middleware()[0])->toBeInstanceOf(PauseWhenBudgetSpent::class);
});

it('marks the features nobody waits on as background', function () {
    $ladder = app(Ladder::class);

    expect($ladder->isBackground('triage.draft'))->toBeTrue()
        ->and($ladder->isBackground('reminder.draft'))->toBeTrue()
        ->and($ladder->isBackground('support.reply'))->toBeFalse()
        ->and($ladder->rungs('support.reply'))->toBe(['cheaper_role', 'tighter_limits', 'cache_only', 'search'])
        ->and($ladder->rungs('triage.draft'))->toBe(['pause'])
        ->and($ladder->next('support.reply'))->toBe('cheaper_role')
        ->and($ladder->next('support.reply', 'tighter_limits'))->toBe('cache_only')
        ->and($ladder->next('support.reply', 'search'))->toBe('search');
});
