<?php

namespace App\Console\Commands;

use App\Ai\Agents\Trace;
use App\Models\AgentRun;
use App\Models\TriageRun;
use Illuminate\Console\Command;

class AgentTrace extends Command
{
    protected $signature = 'agent:trace
        {run? : The agent_runs id; the latest run when left out}
        {--ticket= : The latest triage run of this ticket, for example T-10431 or 10431}';

    protected $description = 'Print one agent run as a readable log: the goal, every decision and observation, and the budget';

    public function handle(): int
    {
        $run = $this->find();

        if ($run === null) {
            $this->error('No such agent run.');

            return self::FAILURE;
        }

        $this->line(sprintf('run %d · %s · %s · %s', $run->id, $run->agent, $run->status, $run->feature));

        foreach ($run->trace['entries'] ?? [] as $entry) {
            $this->line(Trace::render([$entry]));
        }

        $this->line(sprintf(
            'budget: %d rounds, %d tokens, %d writes, %.1fs%s',
            $run->rounds,
            $run->tokens,
            $run->writes,
            $run->seconds,
            $run->reason ? " · ended: {$run->reason}" : '',
        ));

        $findings = $run->state['findings'] ?? [];

        if ($findings !== []) {
            $this->line('findings: '.json_encode($findings, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    private function find(): ?AgentRun
    {
        if ($this->option('ticket')) {
            $ticketId = (int) ltrim(strtoupper((string) $this->option('ticket')), 'T-');

            $agentRunId = TriageRun::query()->where('ticket_id', $ticketId)->whereNotNull('agent_run_id')->latest('id')->value('agent_run_id');

            return $agentRunId === null
                ? null
                : AgentRun::find($agentRunId);
        }

        return $this->argument('run')
            ? AgentRun::find($this->argument('run'))
            : AgentRun::query()->latest('id')->first();
    }
}
