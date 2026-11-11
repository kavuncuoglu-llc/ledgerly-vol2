<?php

namespace App\Console\Commands;

use App\Ai\Local\OllamaNative;
use App\Ai\Training\Dataset;
use App\Ai\Training\TemplateCheck\LlamaServerTemplateRenderer;
use App\Ai\Training\TemplateCheck\OllamaTemplateRenderer;
use App\Ai\Training\TemplateCheck\TemplateComparison;
use App\Ai\Training\TemplateCheck\TemplateRenderer;
use App\Ai\Training\TemplateCheck\TrainingTemplateRenderer;
use App\Ai\Training\TemplateCheck\VllmTemplateRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

class AiTemplateCheck extends Command
{
    protected $signature = 'ai:template-check
        {model : The model name on the server (ledgerly-billing)}
        {--against=training : What the server is compared with: training (the tokenizer\'s chat template)}
        {--server=ollama : ollama, llama or vllm}
        {--tokenizer= : The base the adapter was trained on (default config ai.training.tokenizer)}
        {--revision= : The tokenizer repository\'s revision}
        {--rows= : The training rows file whose prompt is rendered (default: the first dataset\'s train.jsonl)}
        {--index=0 : Which row}';

    protected $description = 'Render one training prompt with the training chat template and through a model server, and compare the token ids; exit 1 on any difference (Chapter 40, Topic 5)';

    public function handle(): int
    {
        try {
            $rows = $this->rows();

            $messages = $this->messages($rows);

            $training = new TrainingTemplateRenderer($this->tokenizer(), $rows, (int) $this->option('index'), $this->option('revision') ?: null);

            $serving = $this->serving();

            $trained = $training->render($messages);

            $served = $serving->render($messages);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Could not render: '.$e->getMessage());

            return self::FAILURE;
        }

        $comparison = TemplateComparison::between($trained, $served, $serving->name());

        $this->line($training->name().': '.$trained->count.' tokens');

        $this->line($serving->name().': '.$served->count.' tokens');

        $this->line(($comparison->match ? '✓ ' : '✗ ').$comparison->summary);

        return $comparison->match
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function rows(): string
    {
        $path = (string) ($this->option('rows') ?: Dataset::named('billing-v1')->path('train.jsonl'));

        $full = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! File::exists($full)) {
            throw new InvalidArgumentException("No training rows at {$full}: pass --rows.");
        }

        return $full;
    }

    private function messages(string $rows): array
    {
        $lines = file($rows, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $row = json_decode($lines[(int) $this->option('index')] ?? '', true);

        if (! is_array($row) || ! isset($row['prompt'])) {
            throw new InvalidArgumentException("Row {$this->option('index')} of {$rows} has no prompt.");
        }

        return $row['prompt'];
    }

    private function tokenizer(): string
    {
        return (string) ($this->option('tokenizer') ?: config('ai.training.tokenizer'));
    }

    private function serving(): TemplateRenderer
    {
        if ($this->option('against') !== 'training') {
            throw new InvalidArgumentException('--against is training: the only thing a server is compared with.');
        }

        $model = (string) $this->argument('model');

        return match ($this->option('server')) {
            'ollama' => new OllamaTemplateRenderer(OllamaNative::fromConfig(), $model),
            'llama' => new LlamaServerTemplateRenderer((string) preg_replace('#/v1/?$#', '', (string) config('ai.providers.local-llama.base_url')), config('ai.providers.local-llama.api_key')),
            'vllm' => new VllmTemplateRenderer((string) preg_replace('#/v1/?$#', '', (string) config('ai.providers.vllm.base_url')), $model, config('ai.providers.vllm.api_key')),
            default => throw new InvalidArgumentException('--server is ollama, llama or vllm.'),
        };
    }
}
