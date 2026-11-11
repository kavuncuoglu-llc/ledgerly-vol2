<?php

namespace App\Console\Commands;

use App\Evals\CaseResult;
use App\Evals\GateRunner;
use App\Evals\GateSummary;
use App\Evals\GoldenSet;
use App\Facades\Ai;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class EvalGates extends Command
{
    protected $signature = 'eval:gates
        {--set=golden : The golden set (tests/golden)}
        {--sample=all : all, smoke, human, synthetic, production, or a number of cases}
        {--no-similarity : Skip the loose similarity gate (it needs the embedding model)}
        {--out=storage/eval/last-gates.json : Where to write the per-case results}';

    protected $description = 'Run the golden set through the whole assistant pipeline and apply the heuristic gates (Chapter 29)';

    public function handle(GateRunner $runner, GoldenSet $set): int
    {
        if ($this->option('set') !== 'golden') {
            $this->error('Only the golden set exists: --set=golden.');

            return self::FAILURE;
        }

        try {
            $cases = $this->cases($set);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($cases->isEmpty()) {
            $this->error('No golden cases found in tests/golden.');

            return self::FAILURE;
        }

        $profile = (string) config('ai.eval.profile', 'deterministic');

        $this->line($cases->count().' cases · '.Ai::providerFor('support.reply').' ('.Ai::model(Ai::providerFor('support.reply')).', '.$this->samplingNote($profile).')');

        $summary = $runner->run($cases, ! $this->option('no-similarity'), fn (CaseResult $result, int $done, int $total) => $this->progress($result, $done, $total));

        $this->report($summary);

        $this->save($summary);

        return $summary->passed()
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function cases(GoldenSet $set)
    {
        $sample = (string) $this->option('sample');

        if (ctype_digit($sample)) {
            return $set->smoke((int) $sample);
        }

        return $set->subset($sample);
    }

    private function progress(CaseResult $result, int $done, int $total): void
    {
        if (! $this->output->isVerbose()) {
            return;
        }

        $this->line(sprintf('  [%d/%d] %s %s', $done, $total, $result->passedQuality() && $result->passedNever() ? 'ok  ' : 'FAIL', $result->case->id));
    }

    private function report(GateSummary $summary): void
    {
        $total = $summary->total();

        $this->line(sprintf(
            'quality gates   %d / %d pass all   (%.1f%%  ≥ %d%% required)   %s',
            $summary->qualityPasses(),
            $total,
            $summary->qualityRate() * 100,
            (int) round($summary->qualityThreshold * 100),
            $summary->qualityOk() ? '✓' : '✗',
        ));

        $this->line(sprintf(
            'never gates     %d / %d pass       (%d allowed failures)       %s',
            $summary->neverPasses(),
            $total,
            $summary->neverAllowed,
            $summary->neverOk() ? '✓' : '✗',
        ));

        foreach ($summary->bySource() as $source => $row) {
            $this->line(sprintf('  %-11s %d cases, %.1f%% pass the quality gates', $source, $row['cases'], $row['quality_pass_rate'] * 100));
        }

        $failing = $summary->failing();

        if ($failing->isNotEmpty()) {
            $this->line('failing cases   '.$failing->take(8)->map(fn (CaseResult $result) => $result->case->id.' ('.$this->shorten($result->toArray()['reason']).')')->implode(', ').($failing->count() > 8 ? ', …' : ''));
        }
    }

    private function save(GateSummary $summary): void
    {
        $out = str_starts_with((string) $this->option('out'), '/')
            ? (string) $this->option('out')
            : base_path((string) $this->option('out'));

        File::ensureDirectoryExists(dirname($out));

        File::put($out, json_encode(['ran_at' => now()->toIso8601String(), 'kind' => 'gates'] + $summary->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function samplingNote(string $profile): string
    {
        $settings = config("ai.profiles.{$profile}", []);

        return 'temperature '.($settings['temperature'] ?? '?').', seed '.($settings['seed'] ?? 'none');
    }

    private function shorten(string $reason): string
    {
        return mb_strimwidth($reason, 0, 90, '…');
    }
}
