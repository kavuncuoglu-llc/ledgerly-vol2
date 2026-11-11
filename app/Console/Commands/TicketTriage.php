<?php

namespace App\Console\Commands;

use App\Ai\Triage\TriagePipeline;
use App\Ai\Triage\TriageResult;
use App\Models\Customer;
use App\Models\ModelCall;
use App\Models\Ticket;
use App\Models\TriageRun;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class TicketTriage extends Command
{
    protected $signature = 'ticket:triage
        {path=storage/samples/tickets.json : A JSON file of {message, label} samples}
        {--customer=12 : The customer the sample tickets are opened for}
        {--limit= : Only the first N samples}';

    protected $description = 'Open each sample message as a ticket and run the triage workflow on it (needs the model server)';

    public function handle(TriagePipeline $pipeline): int
    {
        $customer = Customer::query()->with('owner')->find($this->option('customer'));

        if ($customer?->owner === null) {
            $this->error('Customer '.$this->option('customer').' has no owner to act as. Run php artisan migrate --seed first.');

            return self::FAILURE;
        }

        $samples = $this->samples();

        if ($samples === []) {
            $this->error('No samples found in '.$this->path());

            return self::FAILURE;
        }

        $costBefore = (int) ModelCall::query()->max('id');

        $started = microtime(true);

        $rows = [];

        foreach ($samples as $index => $sample) {
            $ticket = Ticket::create([
                'customer_id' => $customer->id,
                'user_id' => $customer->owner->id,
                'subject' => Str::limit(Str::before($sample['message'], '.'), 70, ''),
                'body' => $sample['message'],
                'source' => 'form',
            ]);

            try {
                $result = $pipeline->run($ticket, TriageRun::create(['event_id' => 'sample-'.Str::uuid(), 'ticket_id' => $ticket->id, 'status' => 'running']));
            } catch (Throwable $e) {
                $this->line(sprintf('#%-2d %s: %s', $index + 1, 'FAILED', $e->getMessage()));

                continue;
            }

            $rows[] = $result;

            $this->line($this->describe($index + 1, $result));
        }

        $this->summary($rows, count($samples), microtime(true) - $started, $costBefore);

        return self::SUCCESS;
    }

    private function describe(int $number, TriageResult $result): string
    {
        $run = $result->run;

        $votes = $run->votes ?? [];

        $share = $votes === []
            ? ''
            : '('.count(array_filter($votes, fn ($vote) => $vote === $run->category)).'/'.count($votes).')';

        if ($result->human) {
            return rtrim(sprintf('#%-2d %-8s %-6s %-8s → %-15s %s', $number, $run->category ?? '—', $share, '—', 'HUMAN', $result->reason.'   no draft'));
        }

        return rtrim(sprintf(
            '#%-2d %-8s %-6s %-8s → %-15s %s',
            $number,
            $run->category,
            $share,
            $result->urgency->value,
            $result->assignee,
            ($result->investigated() ? $this->investigation($result) : 'no agent').'   '.($result->draft === null ? 'no draft' : 'draft queued'),
        ));
    }

    private function investigation(TriageResult $result): string
    {
        $agent = $result->agentRun;

        $entries = $agent->trace['entries'] ?? [];

        $lookups = count(array_filter($entries, fn (array $entry) => $entry['type'] === 'decision' && preg_match('/→ (lookup_invoice|list_invoices|account_status)\(/', $entry['text']) === 1));

        $text = sprintf('investigate: %d rounds, %d lookups', $agent->rounds, $lookups);

        if ($agent->status === 'exhausted') {
            $text .= ", hit {$agent->reason}";
        }

        $facts = collect($result->findings['invoices'] ?? [])
            ->map(fn (array $invoice) => sprintf('%s %s %dd, fee $%.2f', $invoice['number'], $invoice['status'], $invoice['days_overdue'], $invoice['late_fee']))
            ->implode('; ');

        return $facts === ''
            ? $text
            : "{$text}, findings: {$facts}";
    }

    private function summary(array $results, int $total, float $seconds, int $costBefore): void
    {
        $human = count(array_filter($results, fn (TriageResult $result) => $result->human));

        $agents = count(array_filter($results, fn (TriageResult $result) => $result->investigated()));

        $escalated = count(array_filter($results, fn (TriageResult $result) => $result->run->escalated_to !== null));

        $cost = (int) ModelCall::query()->where('id', '>', $costBefore)->sum('cost_micros');

        $this->line(sprintf(
            '%d tickets · %d automated · %d to human · %d agent runs · %d escalated · avg %.1fs · $%.2f%s',
            $total,
            count($results) - $human,
            $human,
            $agents,
            $escalated,
            $total === 0 ? 0 : $seconds / $total,
            $cost / 1_000_000,
            $cost === 0 ? ' (local)' : '',
        ));
    }

    private function path(): string
    {
        $path = (string) $this->argument('path');

        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }

    private function samples(): array
    {
        if (! is_file($this->path())) {
            return [];
        }

        $samples = json_decode((string) file_get_contents($this->path()), true) ?? [];

        $limit = $this->option('limit');

        return $limit
            ? array_slice($samples, 0, (int) $limit)
            : $samples;
    }
}
