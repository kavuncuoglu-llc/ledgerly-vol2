<?php

namespace App\Console\Commands;

use App\Models\TriageRun;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TriageReview extends Command
{
    protected $signature = 'triage:review
        {--show= : Print the whole draft of this triage run}
        {--approve= : Approve this triage run\'s draft}
        {--reject= : Reject this triage run\'s draft}';

    protected $description = 'The review queue: drafts the triage wrote, waiting for a person (nothing reaches a customer unapproved)';

    public function handle(): int
    {
        foreach (['approve' => 'approved', 'reject' => 'rejected'] as $option => $status) {
            if ($this->option($option)) {
                return $this->decide((int) $this->option($option), $status);
            }
        }

        if ($this->option('show')) {
            return $this->show((int) $this->option('show'));
        }

        return $this->list();
    }

    private function list(): int
    {
        $queued = TriageRun::query()->with('ticket')->where('draft_status', 'queued')->orderBy('id')->get();

        if ($queued->isEmpty()) {
            $this->line('The review queue is empty.');

            return self::SUCCESS;
        }

        foreach ($queued as $run) {
            $this->line(sprintf(
                '%-4d T-%-6d %-8s %-8s %-18s %-7s %s',
                $run->id,
                $run->ticket_id,
                $run->category,
                $run->urgency,
                $run->assignee,
                $run->draft_citations_ok ? 'cited' : 'CHECK',
                Str::limit(str_replace("\n", ' ', (string) $run->draft), 70),
            ));
        }

        $this->line($queued->count().' drafts waiting. --show=ID to read one, --approve=ID or --reject=ID to decide.');

        return self::SUCCESS;
    }

    private function show(int $id): int
    {
        $run = TriageRun::find($id);

        if ($run === null || $run->draft === null) {
            $this->error('No draft for that triage run.');

            return self::FAILURE;
        }

        $this->line($run->draft);

        if ($run->findings) {
            $this->line('findings: '.json_encode($run->findings, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    private function decide(int $id, string $status): int
    {
        $run = TriageRun::query()->where('draft_status', 'queued')->find($id);

        if ($run === null) {
            $this->error('No queued draft with that id.');

            return self::FAILURE;
        }

        $run->update(['draft_status' => $status]);

        $this->line("Draft {$status}. (Sending is a separate step; nothing was sent.)");

        return self::SUCCESS;
    }
}
