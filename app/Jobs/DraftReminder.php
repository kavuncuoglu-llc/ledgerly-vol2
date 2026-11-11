<?php

namespace App\Jobs;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\ReminderPrompt;
use App\Jobs\Middleware\PauseWhenBudgetSpent;
use App\Models\Invoice;
use App\Models\ReminderDraft;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

// One model call per overdue invoice, on the ai-batch queue: it never competes with a customer waiting for a reply (Horizon runs ai before ai-batch).
final class DraftReminder implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(public readonly int $invoiceId)
    {
        $this->onQueue('ai-batch');
    }

    public function middleware(): array
    {
        return [new PauseWhenBudgetSpent];                                         // Chapter 36: a reminder draft waits for the budget to reset instead of failing
    }

    public function handle(AiManager $ai): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if (ReminderDraft::query()->where('invoice_id', $this->invoiceId)->exists()) {
            return;                                                                // a retry, or a second batch: the first draft stands
        }

        $invoice = Invoice::query()->with('customer')->findOrFail($this->invoiceId);

        $response = $ai->for('reminder.draft')->chat(ChatRequest::make(ReminderPrompt::messages($invoice), [
            'profile' => 'assistant',
            'max_tokens' => 200,
            'prompt_version' => ReminderPrompt::VERSION,
        ], 'reminder.draft'));

        ReminderDraft::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            ['customer_id' => $invoice->customer_id, 'prompt_version' => ReminderPrompt::VERSION, 'body' => trim((string) $response->content), 'status' => 'draft'],
        );
    }
}
