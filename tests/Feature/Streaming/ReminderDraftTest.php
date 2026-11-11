<?php

use App\Ai\AiManager;
use App\Ai\Prompts\ReminderPrompt;
use App\Ai\Tokens\TokenCounter;
use App\Jobs\DraftReminder;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ModelCall;
use App\Models\ReminderDraft;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->overdue = Invoice::factory()->for(Customer::factory()->create())->overdue(18)->create(['number' => 'INV-5001', 'total' => 500]);
    $this->paid = Invoice::factory()->for(Customer::factory()->create())->create(['number' => 'INV-5002', 'status' => 'paid']);
});

it('fits a small budget and is versioned with its text', function () {
    expect(app(TokenCounter::class)->count(ReminderPrompt::system()))->toBeLessThanOrEqual(200)
        ->and(md5(ReminderPrompt::system()))->toBe('5a99ec2dd1206634ad332a51f0af79f3');   // bump VERSION and this hash together
});

it('gives the model only the facts of one invoice', function () {
    $messages = ReminderPrompt::messages($this->overdue->load('customer'));

    $facts = json_decode(substr($messages[1]['content'], strlen('Invoice facts: ')), true);

    expect($facts)->toHaveKeys(['invoice_number', 'client', 'amount_due', 'due_date', 'days_overdue'])
        ->and($facts['invoice_number'])->toBe('INV-5001')
        ->and($facts['days_overdue'])->toBe(18);
});

it('queues one batch of drafts on ai-batch for overdue invoices that have none', function () {
    Bus::fake();

    $this->artisan('reminders:draft')->expectsOutputToContain('1 reminders queued on ai-batch')->assertSuccessful();

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->queue() === 'ai-batch'
        && $batch->allowsFailures()
        && $batch->jobs->count() === 1
        && $batch->jobs->first() instanceof DraftReminder
        && $batch->jobs->first()->invoiceId === $this->overdue->id);
});

it('does not queue an invoice that already has a draft', function () {
    Bus::fake();

    ReminderDraft::create(['invoice_id' => $this->overdue->id, 'customer_id' => $this->overdue->customer_id, 'prompt_version' => 'x', 'body' => 'Already drafted.']);

    $this->artisan('reminders:draft')->expectsOutputToContain('Nothing to draft')->assertSuccessful();

    Bus::assertNothingBatched();
});

it('writes a draft, logs the call, and is a no-op the second time', function () {
    Http::fake(['*/chat/completions' => Http::response(chatBody('Hi, a quick reminder that INV-5001 is 18 days overdue.'))]);

    (new DraftReminder($this->overdue->id))->handle(app(AiManager::class));
    (new DraftReminder($this->overdue->id))->handle(app(AiManager::class));

    expect(ReminderDraft::sole()->body)->toBe('Hi, a quick reminder that INV-5001 is 18 days overdue.')
        ->and(ReminderDraft::sole()->status)->toBe('draft')
        ->and(ModelCall::sole()->feature)->toBe('reminder.draft')
        ->and(Http::recorded())->toHaveCount(1);
});

it('runs through the ai-batch queue and never sends anything', function () {
    Queue::fake();

    $job = new DraftReminder($this->overdue->id);

    expect($job->queue)->toBe('ai-batch');
});
