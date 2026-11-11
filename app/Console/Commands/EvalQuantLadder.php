<?php

namespace App\Console\Commands;

use App\Ai\Quantization\LadderResult;
use App\Ai\Quantization\QuantLadder;
use App\Ai\Registry\Registry;
use App\Models\ModelRegistryEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

class EvalQuantLadder extends Command
{
    protected $signature = 'eval:quant-ladder
        {model : The fine-tune as the registry knows it (ledgerly-billing-ft), or --f16 names its 16-bit GGUF}
        {--base= : The base model, for the report (llama3.1:8b-instruct). It is compared at the same levels, so its 16-bit GGUF is needed: --base-f16}
        {--base-f16= : The base\'s 16-bit GGUF, quantized here to the same levels as the fine-tune}
        {--f16= : The fine-tune\'s 16-bit GGUF (default: <name>.f16.gguf beside the registry entry\'s GGUF)}
        {--levels=f16,q8_0,q5_k_m,q4_k_m+imatrix,q4_k_m,q3_k_m : The ladder}
        {--set=golden:billing : golden:billing (tests/golden/billing_holdout.jsonl) or the path of a jsonl of cases that carry a prompt}
        {--metrics=required_facts,golden_pass,ttft,decode_tps : What to print}
        {--calibration= : Task text for the importance matrix (default: made from the dataset the registry entry was trained on)}
        {--limit= : Use only the first N cases}
        {--floor=0.95 : The scorecard\'s floor for the golden pass rate}
        {--margin=0.01 : How far above the floor a level must be}
        {--max-loss=1.0 : How many points of the 16-bit advantage a level may give up}
        {--pick-smallest-passing : Print the level to serve}
        {--record= : name:version: keep the ladder and the chosen level with that registry entry}';

    protected $description = 'Compare a fine-tune with its base at each quantization level and pick the smallest level that keeps the fine-tune\'s advantage (Chapter 44, Topic 3)';

    public function handle(QuantLadder $ladder, Registry $registry): int
    {
        try {
            if ($this->option('base') && ! $this->option('base-f16')) {
                throw new InvalidArgumentException('The base is compared at the same levels, and an Ollama tag (llama3.1:8b-instruct) is already quantized: name its 16-bit GGUF with --base-f16 (convert_hf_to_gguf.py makes it), and it is quantized here exactly as the fine-tune is.');
            }

            $entry = ModelRegistryEntry::query()->where('model', $this->argument('model'))->latest('id')->first();

            $result = $ladder->run(
                tunedF16: $this->tunedF16($entry),
                baseF16: $this->option('base-f16'),
                levels: array_values(array_filter(array_map('trim', explode(',', (string) $this->option('levels'))))),
                cases: $this->cases(),
                calibration: $this->calibration($entry),
                directory: storage_path('app/quant-ladder/'.preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $this->argument('model'))),
                rule: ['floor' => (float) $this->option('floor'), 'margin' => (float) $this->option('margin'), 'max_loss' => (float) $this->option('max-loss') / 100],
                progress: fn (string $level) => $this->line("  measuring {$level}"),
            );
        } catch (InvalidArgumentException|Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->print($result);

        File::ensureDirectoryExists(storage_path('eval'));

        File::put(storage_path('eval/quant-ladder.json'), json_encode($result->toArray(), JSON_PRETTY_PRINT)."\n");

        if ($this->option('record')) {
            $this->record((string) $this->option('record'), $result);
        }

        return $result->selected === null && $this->option('pick-smallest-passing')
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function print(LadderResult $result): void
    {
        $metrics = array_map('trim', explode(',', (string) $this->option('metrics')));

        $this->line(str_pad('level', 18).str_pad('size', 10).str_pad('fine-tune', 12).str_pad('base', 12).str_pad('advantage', 11).'speed');

        foreach ($result->levels as $level => $row) {
            $advantage = $result->advantage($level);

            $this->line(str_pad($level, 18)
                .str_pad(number_format($row['bytes'] / 1e6, 1).' MB', 10)
                .str_pad(number_format($row['tuned']['required_facts'] * 100, 1).'%', 12)
                .str_pad($row['base'] === null ? '—' : number_format($row['base']['required_facts'] * 100, 1).'%', 12)
                .str_pad($advantage === null ? '—' : sprintf('%+.1f', $advantage), 11)
                .(in_array('decode_tps', $metrics, true) ? number_format($row['tuned']['decode_tps'], 1).' tok/s' : '')
                .(in_array('ttft', $metrics, true) ? ' · ttft '.number_format($row['tuned']['ttft_seconds'], 2).' s' : '')
                .(in_array('golden_pass', $metrics, true) ? ' · golden pass '.number_format($row['tuned']['golden_pass'] * 100, 1).'%' : ''));
        }

        if ($this->option('pick-smallest-passing')) {
            $this->line($result->selected === null
                ? "  nothing selected: {$result->reason}"
                : "  selected {$result->selected} · advantage ".($result->advantage($result->selected) === null ? 'not compared' : sprintf('%+.1f points', $result->advantage($result->selected))).' · '.number_format($result->levels[$result->selected]['bytes'] / 1e9, 2).' GB');
        }
    }

    private function tunedF16(?ModelRegistryEntry $entry): string
    {
        if ($this->option('f16')) {
            return (string) $this->option('f16');
        }

        $gguf = $entry?->artifacts['files']['gguf'] ?? null;

        if ($gguf === null) {
            throw new InvalidArgumentException('No 16-bit GGUF: the registry has no entry for this model with a gguf, so pass --f16.');
        }

        $f16 = preg_replace('/\.[A-Za-z0-9_]+\.gguf$/', '.f16.gguf', $gguf);

        if (! is_file($f16)) {
            throw new InvalidArgumentException("No 16-bit GGUF at {$f16}: export with --quant=none, or pass --f16.");
        }

        return $f16;
    }

    private function calibration(?ModelRegistryEntry $entry): ?string
    {
        if ($this->option('calibration')) {
            return (string) $this->option('calibration');
        }

        if (! str_contains((string) $this->option('levels'), 'imatrix')) {
            return null;
        }

        $rows = $entry === null
            ? null
            : storage_path('datasets/'.$entry->dataset.'/train.jsonl');

        if ($rows === null || ! is_file($rows)) {
            throw new InvalidArgumentException('+imatrix needs task text: pass --calibration, or register the model so its dataset\'s train.jsonl can be sampled.');
        }

        $path = storage_path('app/quant-ladder/calibration-'.$entry->dataset.'.txt');

        File::ensureDirectoryExists(dirname($path));

        $text = '';

        foreach (array_slice(file($rows, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), 0, 300) as $line) {
            $row = json_decode($line, true);

            $text .= collect([...($row['prompt'] ?? []), ...($row['completion'] ?? [])])->filter(fn ($turn) => filled($turn['content'] ?? null))->map(fn ($turn) => "{$turn['role']}: {$turn['content']}")->implode("\n")."\n\n";
        }

        File::put($path, $text);

        return $path;
    }

    private function cases(): array
    {
        $set = (string) $this->option('set');

        $path = $set === 'golden:billing'
            ? base_path('tests/golden/billing_holdout.jsonl')
            : (str_starts_with($set, '/') ? $set : base_path($set));

        if (! is_file($path)) {
            throw new InvalidArgumentException("No cases at {$path}: --set is golden:billing or the path of a jsonl whose rows carry a prompt and must_include.");
        }

        $cases = collect(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))->map(fn (string $line) => json_decode($line, true))->filter(fn ($row) => is_array($row) && isset($row['prompt'], $row['must_include']))->values();

        return $cases->take($this->option('limit') === null ? $cases->count() : (int) $this->option('limit'))->all();
    }

    private function record(string $target, LadderResult $result): void
    {
        [$name, $version] = array_pad(explode(':', $target, 2), 2, '');

        $entry = ModelRegistryEntry::query()->where('name', $name)->where('version', (int) $version)->first();

        if ($entry === null) {
            $this->error("No version {$version} of {$name} to record the ladder with.");

            return;
        }

        $entry->update([
            'artifacts' => $entry->artifacts + ['quantization_chosen' => $result->selected],
            'evaluations' => [...(array) $entry->evaluations, ['at' => now()->toDateString(), 'set' => $this->option('set'), 'ladder' => $result->toArray()]],
        ]);

        $this->line("  ladder recorded with {$entry->label()}");
    }
}
