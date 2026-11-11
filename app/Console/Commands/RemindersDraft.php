<?php

namespace App\Console\Commands;

use App\Jobs\DraftReminder;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class RemindersDraft extends Command
{
    protected $signature = 'reminders:draft {--limit=50 : The most invoices to draft in one batch} {--customer= : Only this customer id}';

    protected $description = 'Queue a reminder draft for every overdue invoice that has none, as one batch on the ai-batch queue';

    public function handle(): int
    {
        $ids = $this->overdueWithoutDraft();

        if ($ids === []) {
            $this->info('Nothing to draft: every overdue invoice already has one.');

            return self::SUCCESS;
        }

        $batch = Bus::batch(array_map(fn (int $id) => new DraftReminder($id), $ids))
            ->name('reminder drafts')
            ->onQueue('ai-batch')
            ->allowFailures()
            ->dispatch();

        $this->info(count($ids)." reminders queued on ai-batch (batch {$batch->id}). Nothing is sent: each one waits as a draft.");

        return self::SUCCESS;
    }

    /** @return int[] */
    private function overdueWithoutDraft(): array
    {
        return Invoice::query()
            ->where('status', 'overdue')
            ->whereDoesntHave('reminderDraft')
            ->when($this->option('customer'), fn ($query, $customer) => $query->where('customer_id', $customer))
            ->orderBy('due_at')
            ->limit((int) $this->option('limit'))
            ->pluck('id')
            ->all();
    }
}
