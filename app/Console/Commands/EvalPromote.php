<?php

namespace App\Console\Commands;

use App\Evals\GateRunner;
use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use App\Evals\Redactor;
use App\Evals\ReviewQueue;
use Illuminate\Console\Command;

class EvalPromote extends Command
{
    protected $signature = 'eval:promote
        {--reviewed : Promote the labeled candidates (the only mode; nothing unreviewed is ever promoted)}
        {--customer=acme : The fixture customer the new cases run as}
        {--run : Run the gates on the new cases and say how many fail}';

    protected $description = 'Redact the labeled candidates and write them to tests/golden/production (Chapter 29, Topic 8)';

    public function handle(ReviewQueue $queue, GoldenSet $set, GateRunner $runner): int
    {
        if (! $this->option('reviewed')) {
            $this->error('Pass --reviewed: only a person-labeled candidate becomes a case.');

            return self::FAILURE;
        }

        $labeled = $queue->labeled();

        if ($labeled->isEmpty()) {
            $this->info('Nothing labeled to promote.');

            return self::SUCCESS;
        }

        $cases = $labeled->map(fn (array $row) => new GoldenCase(
            id: 'production-'.$row['message_id'],
            category: $row['label']['category'],
            source: 'production',
            customer: (string) $this->option('customer'),
            question: Redactor::scrub($row['question']),
            expectedBehavior: $row['label']['expected_behavior'],
            mustInclude: $row['label']['must_include'],
            mustNotInclude: $row['label']['must_not_include'],
            added: now()->toDateString(),
            originCall: $row['model_call_id'] ? "model_calls:{$row['model_call_id']}" : null,
        ))->values();

        $path = $set->directory().'/production/'.now()->format('Y-m-d').'.jsonl';

        $existing = file_exists($path)
            ? collect(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))->map(fn (string $line) => GoldenCase::fromArray(json_decode($line, true)))
            : collect();

        $new = $cases->reject(fn (GoldenCase $case) => $existing->contains('id', $case->id) || $set->find($case->id) !== null)->values();

        $set->write($path, $existing->concat($new));

        foreach ($labeled as $row) {
            $queue->update($row['id'], ['status' => 'promoted']);
        }

        $line = "  {$new->count()} new cases · source: production";

        if ($this->option('run') && $new->isNotEmpty()) {
            $failing = $runner->run($new, measureSimilarity: false)->failing()->count();

            $line .= " · gates now fail on {$failing} of them";
        }

        $this->line($line);

        return self::SUCCESS;
    }
}
