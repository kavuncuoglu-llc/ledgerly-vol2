<?php

namespace App\Evals\RoleComparison;

use App\Evals\Tripwire;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

// Chapter 41, Topic 6 and Chapter 42, Topic 8: the tripwire for a role, with lm-evaluation-harness (Chapter 30, Appendix H.5). A hosted model has no per-option probabilities, so only the generation tasks run, through its chat endpoint; a model whose weights are local runs every task. Each role names how in config('ai.compare.tripwire'); the scores land in storage/eval/tripwire/<role>, and a fine-tune is compared with the role in config('ai.compare.bases').
// The harness is run in the training project (`uv run lm_eval`), which can take an hour: the command is written for the versions pinned in training/uv.lock and has been run here only through a fake process.
final class TripwireRunner
{
    public const GENERATION = ['gsm8k', 'ifeval'];

    public function __construct(private Tripwire $tripwire) {}

    /** @return array{drop: float, tasks: array<string, float>} the largest drop in points, and each task's change */
    public function largestDrop(string $role, string $mode, ?string $base = null): array
    {
        $base ??= (string) (config('ai.compare.bases')[$role] ?? '');

        if ($base === '') {
            throw new InvalidArgumentException("No base to compare [{$role}] with: set ai.compare.bases.{$role}.");
        }

        $candidate = $this->scores($role, $mode);

        $reference = $this->scores($base, $mode);

        $rows = $this->tripwire->compare(array_intersect_key($reference, $candidate), $candidate, (float) config('ai.compare.max_drop', 2.0));

        $changes = [];

        foreach ($rows as $row) {
            $changes[$row['task']] = -$row['drop'];
        }

        return ['drop' => $rows === [] ? 0.0 : max(array_column($rows, 'drop')), 'tasks' => $changes];
    }

    /** @return array<string, float> */
    private function scores(string $role, string $mode): array
    {
        $directory = storage_path("eval/tripwire/{$role}-{$mode}");

        if (! is_dir($directory) || glob("{$directory}/*/results_*.json") === []) {
            $this->run($role, $mode, $directory);
        }

        return $this->tripwire->scores($directory);
    }

    private function modelArgs(array $setup): string
    {
        if (! isset($setup['from_provider'])) {
            return (string) $setup['model_args'];
        }

        $provider = config('ai.providers')[$setup['from_provider']];

        return sprintf('model=%s,base_url=%s/chat/completions', $provider['model'], rtrim((string) $provider['base_url'], '/'));
    }

    private function run(string $role, string $mode, string $directory): void
    {
        $setup = config('ai.compare.tripwire')[$role] ?? throw new InvalidArgumentException("No lm_eval setup for [{$role}]: add ai.compare.tripwire.{$role} = ['model' => ..., 'model_args' => ...].");

        $tasks = $mode === 'generation'
            ? self::GENERATION
            : array_keys(Tripwire::METRICS);

        if ($mode === 'all' && ($setup['generation_only'] ?? false)) {
            throw new InvalidArgumentException("[{$role}] is a hosted model with no log-probabilities: only tripwire:generation can run on it.");
        }

        File::ensureDirectoryExists($directory);

        $command = ['uv', 'run', 'lm_eval', '--model', $setup['model'], '--model_args', $this->modelArgs($setup), '--tasks', implode(',', $tasks), '--batch_size', 'auto', '--output_path', $directory];

        if ($setup['generation_only'] ?? false) {
            $command[] = '--apply_chat_template';
        }

        $result = Process::path(base_path('training'))->timeout(7200)->run($command);

        if ($result->failed()) {
            throw new InvalidArgumentException('lm_eval failed for '.$role.': '.mb_substr(trim($result->errorOutput() ?: $result->output()), -300));
        }
    }
}
