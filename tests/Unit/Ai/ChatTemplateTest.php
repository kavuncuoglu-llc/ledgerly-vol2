<?php

use App\Ai\Local\ChatTemplate;

function llama3Template(): string
{
    return <<<'TPL'
{{- if .System }}<|start_header_id|>system<|end_header_id|>

{{ .System }}<|eot_id|>{{ end }}
{{- range .Messages }}<|start_header_id|>{{ .Role }}<|end_header_id|>

{{ .Content }}<|eot_id|>
{{- end }}<|start_header_id|>assistant<|end_header_id|>


TPL;
}

it('finds the special tokens, the slots and the assistant cue in a Llama 3 template', function () {
    $template = new ChatTemplate('llama3.1:8b', llama3Template());

    expect($template->specialTokens())->toBe(['<|start_header_id|>', '<|end_header_id|>', '<|eot_id|>'])
        ->and($template->hasSystemSlot())->toBeTrue()
        ->and($template->hasMessagesLoop())->toBeTrue()
        ->and($template->hasToolsSection())->toBeFalse()
        ->and($template->endsWithOpenAssistantTurn())->toBeTrue();
});

it('sees the tools section when the template has one', function () {
    $template = new ChatTemplate('m', '{{ if .Tools }}Tools: {{ .Tools }}{{ end }}{{ range .Messages }}{{ .Content }}{{ end }}<|im_start|>assistant'."\n");

    expect($template->hasToolsSection())->toBeTrue()
        ->and($template->specialTokens())->toBe(['<|im_start|>']);
});

it('notices a template that forgets to open the assistant turn', function () {
    $template = new ChatTemplate('m', '{{ range .Messages }}<|user|>{{ .Content }}<|end|>{{ end }}');

    expect($template->endsWithOpenAssistantTurn())->toBeFalse();
});

it('finds Gemma style turn markers', function () {
    $template = new ChatTemplate('gemma', '<start_of_turn>user'."\n".'{{ .Content }}<end_of_turn>'."\n".'<start_of_turn>model');

    expect($template->specialTokens())->toBe(['<start_of_turn>', '<end_of_turn>']);
});

it('is built from the /api/show response', function () {
    $template = ChatTemplate::fromShow('llama3.1:8b', [
        'template' => llama3Template(),
        'details' => ['family' => 'llama'],
        'parameters' => 'stop "<|eot_id|>"',
    ]);

    expect($template->family)->toBe('llama')
        ->and($template->parameters)->toBe('stop "<|eot_id|>"')
        ->and($template->isEmpty())->toBeFalse();
});

it('is empty when Ollama sends no template', function () {
    expect(ChatTemplate::fromShow('base-model', ['details' => []])->isEmpty())->toBeTrue();
});
