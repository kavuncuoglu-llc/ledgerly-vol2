<?php

namespace App\Console\Commands;

use App\Ai\Privacy\ErasureSubject;
use App\Ai\Privacy\UserErasure;
use App\Models\ErasureRequest;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Chapter 13 built this for conversations and memories. Chapter 34 makes it cover every copy on the data map that can be deleted on request, report the copies it cannot touch with their expiry, and leave a receipt.
class UserForget extends Command
{
    protected $signature = 'user:forget {user : A user id, or customer:ID for every user of a customer account} {--confirm : Actually delete; without it the command only counts} {--reason= : Why, for the receipt (a request date or a ticket number, not a name)}';

    protected $description = 'Erase everything the assistant stored about a user or a customer account, report what expires on its own, and write a receipt';

    public function handle(CurrentTenant $tenant, UserErasure $erasure): int
    {
        $subject = $tenant->run(null, fn () => ErasureSubject::parse((string) $this->argument('user')));

        if (! $subject->exists()) {
            $this->error("There is no user {$this->argument('user')}.");

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->printReport('would delete', $tenant->run(null, fn () => $erasure->count($subject)));

            $this->warn('Nothing was deleted. Run again with --confirm.');

            return self::FAILURE;
        }

        $report = $tenant->run(null, fn () => $erasure->erase($subject));

        $expiring = $erasure->expiring();

        $receipt = $this->writeReceipt($subject, $report, $expiring);

        Log::info('user.forgotten', ['subject' => $subject->label, 'user_id' => $subject->customerId === null ? $subject->users->first()?->id : null] + $report['deleted']);   // counts only: never the content

        $this->printReport('deleted', $report);

        $this->printExpiring($expiring);

        $this->line("  receipt     erasure_requests #{$receipt->id} written");

        return self::SUCCESS;
    }

    private function printReport(string $verb, array $report): void
    {
        $deleted = $report['deleted'];

        $this->line("  {$verb}  conversations {$deleted['conversations']} · messages {$deleted['messages']} · memories {$deleted['memories']} · tool_calls {$deleted['tool_calls']} · message_sources {$deleted['message_sources']} · agent_runs {$deleted['agent_runs']}");

        $this->line("  {$verb}  semantic-cache entries {$deleted['semantic_cache_entries']} · private passages {$deleted['private_passages']} · golden cases {$deleted['golden_cases']} (production) · vaults {$deleted['vaults']} · pending actions {$deleted['pending_actions']}");

        $this->line("  {$verb}  feedback {$deleted['feedback']} · signals {$deleted['signals']} · shadow answers {$deleted['shadow_answers']} · judgments {$deleted['judgments']}");

        $this->line("  anonymized  model_calls {$report['anonymized']['model_calls']} (the row stays for cost history; the conversation link and the recorded request go)");
    }

    private function printExpiring(array $expiring): void
    {
        $this->line("  expiring    logs ({$expiring['logs']}) · traces ({$expiring['traces']}) · backups ({$expiring['backups']}) · provider copies ({$expiring['provider_copies']})");
    }

    private function writeReceipt(ErasureSubject $subject, array $report, array $expiring): ErasureRequest
    {
        return DB::transaction(fn () => ErasureRequest::create([
            'subject' => $subject->label,
            'reason' => $this->option('reason'),
            'deleted' => $report['deleted'],
            'anonymized' => $report['anonymized'],
            'expiring' => $expiring,
        ]));
    }
}
