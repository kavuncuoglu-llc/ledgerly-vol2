<?php

namespace App\Console\Commands;

use App\Ai\Events\ModelCalled;
use App\Ai\Local\OllamaNative;
use App\Ai\Timings\TimingsProbe;
use App\Ai\Timings\TimingsResult;
use App\Help\HelpArticle;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class AiTimings extends Command
{
    protected $signature = 'ai:timings
        {prompt? : The prompt to send}
        {--model= : Model name (defaults to AI_MODEL)}
        {--article= : Use a help article as a long prompt, e.g. billing/late-fees}
        {--file= : Use the contents of a file as the prompt}
        {--api=auto : auto | native | openai. Only the native API reports prefill and decode timings}
        {--repeat=1 : Run the same prompt this many times (the second run shows load duration disappear)}
        {--show : Print the model reply}
        {--no-log : Do not write a model_calls row}';

    protected $description = 'Send one prompt and print the timing breakdown ollama run --verbose shows (Chapter 3)';

    public function handle(): int
    {
        $prompt = $this->resolvePrompt();

        if ($prompt === '') {
            $this->error('Give a prompt, --article=category/slug or --file=path.');

            return self::FAILURE;
        }

        $probe = new TimingsProbe(OllamaNative::fromConfig());
        $model = $this->option('model') ?: config('ai.model');

        for ($run = 1; $run <= max(1, (int) $this->option('repeat')); $run++) {
            if (! $this->runOnce($probe, $prompt, $model, $run)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function runOnce(TimingsProbe $probe, string $prompt, string $model, int $run): bool
    {
        try {
            $result = $probe->run($prompt, $model, $this->option('api'));
        } catch (ConnectionException $e) {
            $this->error('Could not reach the model server at '.config('ai.base_url').'. Is Ollama running?');

            return false;
        } catch (RequestException $e) {
            $this->error("The model server answered {$e->response->status()}: ".$e->response->body());

            return false;
        }

        $this->printResult($result, $run);
        $this->logCall($result);

        return true;
    }

    private function resolvePrompt(): string
    {
        $prompt = (string) $this->argument('prompt');

        if ($this->option('article')) {
            return $this->articlePrompt($this->option('article'), $prompt);
        }

        if ($this->option('file')) {
            return trim((string) @file_get_contents($this->option('file')).' '.$prompt);
        }

        return $prompt;
    }

    private function articlePrompt(string $path, string $question): string
    {
        [$category, $slug] = array_pad(explode('/', $path, 2), 2, '');

        $article = HelpArticle::find($category, $slug);

        if (! $article) {
            $this->error("No help article '{$path}'.");

            return '';
        }

        $question = $question ?: 'Summarize this in one sentence.';

        return $article->body."\n\n".$question;
    }

    private function printResult(TimingsResult $result, int $run): void
    {
        $this->line("Run {$run}: {$result->model} via {$result->api} API");

        if ($this->option('show')) {
            $this->line($result->content);
            $this->newLine();
        }

        foreach ($result->timings->report() as $label => $value) {
            $this->line(str_pad("{$label}:", 22).$value);
        }

        $this->newLine();
    }

    private function logCall(TimingsResult $result): void
    {
        if ($this->option('no-log')) {
            return;
        }

        ModelCalled::dispatch(
            'ai.timings',
            config('ai.provider'),
            $result->model,
            $result->usage(),
            $result->finishReason,
            $result->timings->totalMs(),
        );
    }
}
