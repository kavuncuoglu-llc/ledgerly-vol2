<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Encoding\BenchPrompt;
use App\Ai\Encoding\DataEncoder;
use App\Ai\Shapes\InvoiceSummaryShape;
use App\Ai\Tokens\TokenCounter;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class AiBenchEncoding extends Command
{
    protected $signature = 'ai:bench-encoding
        {--model= : The model that reads the data (default config ai.model)}
        {--rows=50 : How many fixture invoices to encode}
        {--questions=storage/benchmarks/invoice-questions.json : Questions with known answers}
        {--fixture=storage/benchmarks/invoices.json : The invoice fixture (120 rows)}
        {--tokens-only : Count tokens only; do not call the model}
        {--no-save : Do not write the results file}';

    protected $description = 'Measure token cost and comprehension of each data encoding on the invoice fixture';

    private const ENCODINGS = [
        'json-pretty' => 'json-pretty',
        'json-compact' => 'json',
        'yaml' => 'yaml',
        'markdown-table' => 'markdown',
        'tsv' => 'tsv',
        'toon' => 'toon',
    ];

    public function handle(DataEncoder $encoder, TokenCounter $counter, AiManager $ai): int
    {
        $fixture = base_path($this->option('fixture'));
        $questionsPath = base_path($this->option('questions'));

        if (! File::exists($fixture)) {
            $this->error("No fixture at {$this->option('fixture')}.");

            return self::FAILURE;
        }

        $rows = (int) $this->option('rows');
        $invoices = $this->invoices($fixture, $rows);
        $model = $this->option('model') ?: config('ai.model');

        $questions = $this->questions($questionsPath, $rows);

        $results = $this->measureTokens($encoder, $counter, $invoices, $rows);

        if ($questions !== [] && ! $this->option('tokens-only')) {
            try {
                $results = $this->measureComprehension($results, $ai, $questions, $model);
            } catch (ConnectionException) {
                $this->error('Could not reach the model server at '.config('ai.base_url').'. Use --tokens-only to count without a model.');

                return self::FAILURE;
            } catch (RequestException $e) {
                $this->error('The model server answered '.$e->response->status().'.');

                return self::FAILURE;
            }
        }

        $this->printTable($results, count($questions));
        $this->line(sprintf('toJson() of the same %d models, all 16 columns: %s tokens', $invoices->count(), number_format($counter->count($invoices->toJson()))));

        if (! $this->option('no-save')) {
            $this->save($results, $model, $rows);
        }

        return self::SUCCESS;
    }

    private function invoices(string $fixture, int $rows): Collection
    {
        return collect(json_decode(File::get($fixture), true))
            ->take($rows)
            ->map(fn (array $row) => (new Invoice)->forceFill($row))
            ->values();
    }

    private function questions(string $path, int $rows): array
    {
        if ($this->option('tokens-only')) {
            return [];
        }

        if (! File::exists($path)) {
            $this->warn("No questions at {$this->option('questions')}; counting tokens only.");

            return [];
        }

        $file = json_decode(File::get($path), true);

        if (($file['rows'] ?? $rows) !== $rows) {
            $this->warn("The expected answers were written for the first {$file['rows']} rows, so comprehension is skipped at --rows={$rows}.");

            return [];
        }

        return $file['questions'];
    }

    private function measureTokens(DataEncoder $encoder, TokenCounter $counter, Collection $invoices, int $rows): array
    {
        $results = [];

        foreach (self::ENCODINGS as $name => $format) {
            $data = $encoder->encode($invoices, new InvoiceSummaryShape($rows), $format, 'invoices');

            $results[$name] = ['format' => $format, 'data' => $data, 'tokens' => $counter->count($data), 'correct' => null];
        }

        return $results;
    }

    private function measureComprehension(array $results, AiManager $ai, array $questions, string $model): array
    {
        foreach ($results as $name => $result) {
            $correct = 0;

            foreach ($questions as $q) {
                $answer = $ai->for('bench.encoding')->chat(ChatRequest::make(
                    BenchPrompt::messages($result['data'], $q['question']),
                    ['profile' => 'deterministic', 'max_tokens' => 20, 'model' => $model],
                    'bench.encoding',
                ))->content ?? '';

                $correct += (int) BenchPrompt::matches($answer, $q['expected']);
            }

            $results[$name]['correct'] = $correct;
        }

        return $results;
    }

    private function printTable(array $results, int $questionCount): void
    {
        $baseline = $results['json-compact']['tokens'];

        $this->line(sprintf('%-18s %8s %9s   %-22s %s', 'encoding', 'tokens', 'vs json', "comprehension ({$questionCount} q)", 'tokens/correct'));

        foreach ($results as $name => $result) {
            $this->line(sprintf(
                '%-18s %8s %9s   %-22s %s',
                $name,
                number_format($result['tokens']),
                $name === 'json-compact' ? 'baseline' : sprintf('%+d%%', round(($result['tokens'] / $baseline - 1) * 100)),
                $result['correct'] === null ? 'n/a' : "{$result['correct']} / {$questionCount}",
                $result['correct'] ? number_format($result['tokens'] / $result['correct']) : 'n/a',
            ));
        }
    }

    private function save(array $results, string $model, int $rows): void
    {
        $directory = storage_path('benchmarks');

        File::ensureDirectoryExists($directory);

        $slug = trim(preg_replace('/[^A-Za-z0-9.]+/', '-', $model), '-');
        $path = $directory.'/'.now()->toDateString().'-'.$slug.'.json';

        File::put($path, json_encode([
            'model' => $model,
            'date' => now()->toDateString(),
            'rows' => $rows,
            'counter' => config('ai.token_counter'),
            'results' => array_map(fn (array $r) => ['tokens' => $r['tokens'], 'correct' => $r['correct']], $results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->newLine();
        $this->line('wrote storage/benchmarks/'.basename($path));
    }
}
