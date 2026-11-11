<?php

use App\Ai\Local\OllamaNative;
use App\Ai\Training\TemplateCheck\LlamaServerTemplateRenderer;
use App\Ai\Training\TemplateCheck\OllamaTemplateRenderer;
use App\Ai\Training\TemplateCheck\RenderedPrompt;
use App\Ai\Training\TemplateCheck\TemplateComparison;
use App\Ai\Training\TemplateCheck\VllmTemplateRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

// Chapter 40, Topic 5: the same messages, rendered by the trainer and by the server, must be the same tokens. No test starts a server or Python: the training side is a faked process and the servers are faked HTTP.
it('compares by id and names the first token that differs', function () {
    $same = TemplateComparison::between(RenderedPrompt::of([1, 2, 3]), RenderedPrompt::of([1, 2, 3]), 'llama-server');
    $differs = TemplateComparison::between(RenderedPrompt::of([128000, 5, 6]), RenderedPrompt::of([128000, 128000, 5, 6]), 'llama-server');
    $shorter = TemplateComparison::between(RenderedPrompt::of([1, 2, 3, 4]), RenderedPrompt::of([1, 2]), 'vllm');

    expect($same->match)->toBeTrue()
        ->and($differs->match)->toBeFalse()
        ->and($differs->firstDifference)->toBe(1)
        ->and($differs->summary)->toContain('first difference at token 1')
        ->and($shorter->summary)->toContain('first 2 tokens agree');
});

it('compares by count when the server only says how many tokens it evaluated', function () {
    expect(TemplateComparison::between(RenderedPrompt::of([1, 2, 3]), new RenderedPrompt(3), 'ollama')->match)->toBeTrue()
        ->and(TemplateComparison::between(RenderedPrompt::of([1, 2, 3]), new RenderedPrompt(4), 'ollama')->summary)->toContain('training 3 tokens, ollama 4');
});

it('renders through llama-server with apply-template and tokenize', function () {
    Http::fake([
        '*/apply-template' => Http::response(['prompt' => '<|begin_of_text|>hi']),
        '*/tokenize' => Http::response(['tokens' => [128000, 6151]]),
    ]);

    $rendered = (new LlamaServerTemplateRenderer('http://llama:8080', 'key'))->render([['role' => 'user', 'content' => 'hi']]);

    expect($rendered->ids)->toBe([128000, 6151])
        ->and($rendered->text)->toBe('<|begin_of_text|>hi');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/tokenize') && $request['parse_special'] === true && $request['add_special'] === false && $request->hasHeader('Authorization', 'Bearer key'));
});

it('renders through vLLM with the messages and the generation prompt', function () {
    Http::fake(['*/tokenize' => Http::response(['tokens' => [1, 2, 3]])]);

    $rendered = (new VllmTemplateRenderer('http://vllm:8000', 'billing-v1'))->render([['role' => 'user', 'content' => 'hi']]);

    expect($rendered->ids)->toBe([1, 2, 3]);

    Http::assertSent(fn ($request) => $request['model'] === 'billing-v1' && $request['add_generation_prompt'] === true && $request['messages'][0]['content'] === 'hi');
});

it('asks Ollama for one token and reads how many prompt tokens it evaluated, with tool arguments as objects', function () {
    Http::fake(['*/api/chat' => Http::response(['prompt_eval_count' => 41, 'message' => ['content' => 'x']])]);

    $messages = [['role' => 'assistant', 'tool_calls' => [['id' => '1', 'type' => 'function', 'function' => ['name' => 'lookup_invoice', 'arguments' => '{"invoice_number":"INV-1"}']]]]];

    $rendered = (new OllamaTemplateRenderer(new OllamaNative('http://localhost:11434/v1'), 'ledgerly-billing'))->render($messages);

    expect($rendered->count)->toBe(41)->and($rendered->ids)->toBe([]);

    Http::assertSent(fn ($request) => $request['options']['num_predict'] === 1 && $request['messages'][0]['tool_calls'][0]['function']['arguments'] === ['invoice_number' => 'INV-1']);
});

function trTemplateRows(): string
{
    $path = sys_get_temp_dir().'/template-rows-'.bin2hex(random_bytes(4)).'.jsonl';

    File::put($path, json_encode(['prompt' => [['role' => 'system', 'content' => 's'], ['role' => 'user', 'content' => 'u']], 'completion' => [['role' => 'assistant', 'content' => 'a']]])."\n");

    return $path;
}

it('passes when both sides agree, and exits 1 on any difference', function () {
    $rows = trTemplateRows();

    Process::fake(['*' => Process::result(output: "warning\n".json_encode(['text' => 'x', 'ids' => [1, 2, 3, 4]]))]);

    Http::fake(['*/api/chat' => Http::sequence()->push(['prompt_eval_count' => 4])->push(['prompt_eval_count' => 5])]);

    $this->artisan('ai:template-check', ['model' => 'ledgerly-billing', '--rows' => $rows, '--tokenizer' => 'base/model'])
        ->expectsOutputToContain('✓ 4 tokens on both sides')
        ->assertExitCode(0);

    $this->artisan('ai:template-check', ['model' => 'ledgerly-billing', '--rows' => $rows, '--tokenizer' => 'base/model'])
        ->expectsOutputToContain('✗ training 4 tokens, ollama (ledgerly-billing) 5')
        ->assertExitCode(1);

    File::delete($rows);
});

it('runs the training side in the training project with the tokenizer, the rows and the row index', function () {
    $rows = trTemplateRows();

    Process::fake(['*' => Process::result(output: json_encode(['text' => 'x', 'ids' => [1]]))]);

    Http::fake(['*/api/chat' => Http::response(['prompt_eval_count' => 1])]);

    $this->artisan('ai:template-check', ['model' => 'm', '--rows' => $rows, '--tokenizer' => 'base/model', '--revision' => 'abc123', '--index' => 0])->assertExitCode(0);

    Process::assertRan(fn ($process) => $process->path === base_path('training') && $process->command === ['uv', 'run', 'python', 'scripts/template_check.py', 'base/model', '--rows', $rows, '--index', '0', '--revision', 'abc123']);

    File::delete($rows);
});

it('says what failed when the training side or the server cannot answer, and for a bad server name', function () {
    $rows = trTemplateRows();

    Process::fake(['*' => Process::result(errorOutput: 'no such tokenizer', exitCode: 1)]);

    $this->artisan('ai:template-check', ['model' => 'm', '--rows' => $rows, '--tokenizer' => 'x'])->expectsOutputToContain('The training side failed: no such tokenizer')->assertExitCode(1);

    $this->artisan('ai:template-check', ['model' => 'm', '--rows' => $rows, '--server' => 'tgi'])->expectsOutputToContain('--server is ollama, llama or vllm')->assertExitCode(1);

    $this->artisan('ai:template-check', ['model' => 'm', '--rows' => '/nowhere/train.jsonl'])->expectsOutputToContain('No training rows')->assertExitCode(1);

    File::delete($rows);
});
