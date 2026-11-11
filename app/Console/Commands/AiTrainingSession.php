<?php

namespace App\Console\Commands;

use App\Ai\Ledger\TrainingLedger;
use Illuminate\Console\Command;

class AiTrainingSession extends Command
{
    protected $signature = 'ai:training-session
        {action : open or close}
        {--session= : The session id ops/cloud/gpu-session.sh made}
        {--provider= : The GPU provider}
        {--gpu= : The GPU type}
        {--hours=0 : Hours billed (close)}
        {--rate=0 : Dollars an hour}
        {--note= : What happened (close)}';

    protected $description = 'Write the ledger rows of a rented training session: one when it opens, one when it ends (Chapter 43)';

    public function handle(TrainingLedger $ledger): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, ['open', 'close'], true)) {
            $this->error('The action is open or close.');

            return self::FAILURE;
        }

        foreach (['session', 'provider', 'gpu'] as $required) {
            if (blank($this->option($required))) {
                $this->error("Name the {$required}: --{$required}=...");

                return self::FAILURE;
            }
        }

        $written = $ledger->recordSession(
            (string) $this->option('session'),
            $action,
            (string) $this->option('provider'),
            (string) $this->option('gpu'),
            (float) $this->option('hours'),
            (float) $this->option('rate'),
            (string) $this->option('note'),
        );

        $this->line($written
            ? "  ledger: training session row written ({$action})"
            : '  ledger: this session row was already written');

        return self::SUCCESS;
    }
}
