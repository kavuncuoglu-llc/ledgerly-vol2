<?php

namespace App\Console\Commands;

use App\Ai\Registry\GuardrailWatch;
use Illuminate\Console\Command;

class AiGuardrails extends Command
{
    protected $signature = 'ai:guardrails {feature=support.reply : The feature whose scorecard is read}';

    protected $description = 'Read the live scorecard by variant while a model is in a trial or just promoted, and raise GuardrailBreached for each floor crossed (Chapter 44, Topic 7)';

    public function handle(GuardrailWatch $watch): int
    {
        $breaches = $watch->check((string) $this->argument('feature'));

        foreach ($breaches as $breach) {
            $this->line("  GuardrailBreached {$breach->feature} {$breach->metric} {$breach->value} < floor {$breach->floor} ({$breach->variant})");
        }

        $this->info(count($breaches).' guardrail(s) breached.');

        return self::SUCCESS;
    }
}
