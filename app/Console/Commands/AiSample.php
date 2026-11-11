<?php

namespace App\Console\Commands;

use App\Ai\Events\ModelCalled;
use App\Ai\Local\OllamaNative;
use App\Ai\Sampling\Sampler;
use App\Ai\Sampling\SampleResult;
use App\Ai\Sampling\SamplingParameters;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;

class AiSample extends Command
{
    protected $signature = 'ai:sample
        {prompt : The prompt to send}
        {--model= : Model name (defaults to AI_MODEL)}
        {--profile= : Start from a profile in config/ai.php (deterministic, grounded, assistant, creative); flags override it}
        {--temperature= : Divides the logits before softmax; 0 is greedy}
        {--top-p= : Keep the smallest set of tokens reaching this probability mass}
        {--top-k= : Keep only the k most probable tokens (native API)}
        {--min-p= : Keep tokens at least this fraction as likely as the top one (native API)}
        {--repeat-penalty= : Divide the logit of tokens already seen (native API)}
        {--frequency-penalty= : Subtract count times this from seen tokens}
        {--presence-penalty= : Subtract this once from seen tokens}
        {--seed= : Fix the random stream}
        {--stop=* : Stop sequence; repeat the flag for several. \n and \t are understood}
        {--max-tokens= : Cap on completion length}
        {--runs=1 : How many times to send the same request}
        {--api=auto : auto | openai | native. auto uses native only for knobs /v1/ cannot carry}
        {--no-log : Do not write model_calls rows}';

    protected $description = 'Send a prompt with chosen sampling parameters and print the completion, finish reason and token counts (Chapter 5)';

    public function handle(): int
    {
        $sampler = new Sampler(OllamaNative::fromConfig());

        try {
            $parameters = $this->parameters();
            $sampler->chooseApi($parameters, $this->option('api'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $model = $this->option('model') ?: config('ai.model');

        $this->warnAboutIgnoredKnobs($sampler, $parameters);

        $outputs = $this->runAll($sampler, $model, $parameters);

        if ($outputs === null) {
            return self::FAILURE;
        }

        $this->summarise($outputs);

        return self::SUCCESS;
    }

    private function runAll(Sampler $sampler, string $model, SamplingParameters $parameters): ?array
    {
        $outputs = [];

        for ($run = 1; $run <= max(1, (int) $this->option('runs')); $run++) {
            $result = $this->sampleOnce($sampler, $model, $parameters);

            if ($result === null) {
                return null;
            }

            $this->printRun($run, $result);
            $this->logCall($result);

            $outputs[] = $result->content;
        }

        return $outputs;
    }

    private function sampleOnce(Sampler $sampler, string $model, SamplingParameters $parameters): ?SampleResult
    {
        try {
            return $sampler->sample($this->argument('prompt'), $model, $parameters, $this->option('api'));
        } catch (ConnectionException $e) {
            $this->error('Could not reach the model server at '.config('ai.base_url').'. Is Ollama running?');
        } catch (RequestException $e) {
            $this->error("The model server answered {$e->response->status()}: ".$e->response->body());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
        }

        return null;
    }

    private function parameters(): SamplingParameters
    {
        $base = $this->option('profile')
            ? SamplingParameters::fromProfile($this->option('profile'))
            : new SamplingParameters;

        return $base->merge([
            'temperature' => $this->number('temperature'),
            'top_p' => $this->number('top-p'),
            'top_k' => $this->integer('top-k'),
            'min_p' => $this->number('min-p'),
            'repeat_penalty' => $this->number('repeat-penalty'),
            'frequency_penalty' => $this->number('frequency-penalty'),
            'presence_penalty' => $this->number('presence-penalty'),
            'seed' => $this->integer('seed'),
            'max_tokens' => $this->integer('max-tokens'),
            'stop' => array_map(stripcslashes(...), $this->option('stop')),
        ]);
    }

    private function number(string $option): ?float
    {
        $value = $this->option($option);

        return $value === null
            ? null
            : (float) $value;
    }

    private function integer(string $option): ?int
    {
        $value = $this->option($option);

        return $value === null
            ? null
            : (int) $value;
    }

    private function warnAboutIgnoredKnobs(Sampler $sampler, SamplingParameters $parameters): void
    {
        if ($sampler->chooseApi($parameters, $this->option('api')) !== Sampler::API_OPENAI) {
            return;
        }

        if (! $parameters->needsNativeApi()) {
            return;
        }

        $this->warn('The OpenAI-compatible endpoint has no field for: '.implode(', ', $parameters->nativeOnlyKeys()).'. Use --api=native or leave --api=auto.');
    }

    private function printRun(int $run, SampleResult $result): void
    {
        $lines = explode("\n", wordwrap(trim(preg_replace('/\s+/', ' ', $result->content)), 76, "\n"));
        $last = array_pop($lines);

        foreach ($lines as $index => $line) {
            $this->line(($index === 0 ? "[{$run}] " : '    ').$line);
        }

        $prefix = $lines === []
            ? "[{$run}] "
            : '    ';

        $this->line($prefix.str_pad($last, 60).sprintf('  %-6s · in %d  out %d', $result->finishReason, $result->promptTokens, $result->completionTokens));
    }

    private function summarise(array $outputs): void
    {
        if (count($outputs) < 2) {
            return;
        }

        $distinct = count(array_unique($outputs));

        $this->newLine();
        $this->line("{$distinct} distinct output(s) in ".count($outputs).' runs');
    }

    private function logCall(SampleResult $result): void
    {
        if ($this->option('no-log')) {
            return;
        }

        ModelCalled::dispatch(
            'ai.sample',
            config('ai.provider'),
            $result->model,
            $result->usage(),
            $result->finishReason,
            $result->latencyMs,
        );
    }
}
