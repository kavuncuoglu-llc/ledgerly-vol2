<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['ai.base_url' => 'http://ollama.test:11434/v1', 'ai.model' => 'llama3.1:8b']);
});

function showResponse(): array
{
    return [
        'template' => "{{- if .System }}<|start_header_id|>system<|end_header_id|>\n\n{{ .System }}<|eot_id|>{{ end }}\n{{- range .Messages }}<|start_header_id|>{{ .Role }}<|end_header_id|>\n\n{{ .Content }}<|eot_id|>\n{{- end }}<|start_header_id|>assistant<|end_header_id|>\n\n",
        'details' => ['family' => 'llama'],
        'parameters' => 'stop "<|eot_id|>"',
    ];
}

it('prints the template and what it contains', function () {
    Http::fake(['ollama.test:11434/api/show' => Http::response(showResponse())]);

    $this->artisan('ai:template')
        ->expectsOutputToContain('Model: llama3.1:8b (llama)')
        ->expectsOutputToContain('<|start_header_id|>{{ .Role }}<|end_header_id|>')
        ->expectsOutputToContain('Special tokens:         <|start_header_id|> <|end_header_id|> <|eot_id|>')
        ->expectsOutputToContain('Tools section:          no')
        ->expectsOutputToContain('Ends on assistant cue:  yes')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama.test:11434/api/show'
        && $request['model'] === 'llama3.1:8b');
});

it('takes the model from the argument', function () {
    Http::fake(['ollama.test:11434/api/show' => Http::response(showResponse())]);

    $this->artisan('ai:template', ['model' => 'llama3.2:3b'])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['model'] === 'llama3.2:3b');
});

it('saves the template to a file', function () {
    Http::fake(['ollama.test:11434/api/show' => Http::response(showResponse())]);
    $path = tempnam(sys_get_temp_dir(), 'template');

    $this->artisan('ai:template', ['--save' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toBe(showResponse()['template']);

    unlink($path);
});

it('runs the same prompt raw and through chat', function () {
    Http::fake([
        'ollama.test:11434/api/show' => Http::response(showResponse()),
        'ollama.test:11434/api/generate' => Http::response(['response' => ' A. Late fees are\n\nQ. How do I waive one?']),
        'ollama.test:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'A late fee is a charge for paying after the due date.']]),
    ]);

    $this->artisan('ai:template', ['--experiment' => 'What is a late fee on an invoice?'])
        ->expectsOutputToContain('raw (template off)')
        ->expectsOutputToContain('Q. How do I waive one?')
        ->expectsOutputToContain('chat (template on)')
        ->expectsOutputToContain('A late fee is a charge for paying after the due date.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/generate')
        && $request['raw'] === true
        && $request['stream'] === false
        && $request['prompt'] === 'What is a late fee on an invoice?');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/chat')
        && $request['messages'][0] === ['role' => 'user', 'content' => 'What is a late fee on an invoice?']);
});

it('fails when the model has no template', function () {
    Http::fake(['ollama.test:11434/api/show' => Http::response(['details' => []])]);

    $this->artisan('ai:template')
        ->expectsOutputToContain('Ollama reports no template for llama3.1:8b.')
        ->assertFailed();
});

it('fails clearly when the model is not pulled', function () {
    Http::fake(['ollama.test:11434/api/show' => Http::response(['error' => 'model not found'], 404)]);

    $this->artisan('ai:template')
        ->expectsOutputToContain('The model server answered 404')
        ->assertFailed();
});

it('says so when the model server is not running', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('ai:template')
        ->expectsOutputToContain('Could not reach the model server')
        ->assertFailed();
});
