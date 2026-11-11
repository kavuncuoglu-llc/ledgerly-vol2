<?php

namespace App\Ai\Triage;

use App\Models\AgentRun;
use App\Models\Ticket;
use App\Models\TriageRun;

final readonly class TriageResult
{
    public function __construct(
        public Ticket $ticket,
        public TriageRun $run,
        public bool $human,
        public ?string $reason = null,
        public ?Urgency $urgency = null,
        public ?string $assignee = null,
        public array $findings = [],
        public ?Draft $draft = null,
        public ?AgentRun $agentRun = null,
    ) {}

    public function investigated(): bool
    {
        return $this->agentRun !== null;
    }
}
