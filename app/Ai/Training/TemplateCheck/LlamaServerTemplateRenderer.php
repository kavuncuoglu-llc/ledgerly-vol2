<?php

namespace App\Ai\Training\TemplateCheck;

use Illuminate\Support\Facades\Http;

// llama-server renders messages to the prompt string with POST /apply-template and tokenizes with POST /tokenize; special tokens in the string are parsed, none are added a second time.
final class LlamaServerTemplateRenderer implements TemplateRenderer
{
    public function __construct(private string $baseUrl, private ?string $apiKey = null) {}

    public function name(): string
    {
        return 'llama-server';
    }

    public function render(array $messages): RenderedPrompt
    {
        $http = Http::baseUrl(rtrim($this->baseUrl, '/'))->acceptJson()->timeout(60);

        if ($this->apiKey) {
            $http = $http->withToken($this->apiKey);
        }

        $text = (string) $http->post('/apply-template', ['messages' => $messages])->throw()->json('prompt');

        $ids = $http->post('/tokenize', ['content' => $text, 'add_special' => false, 'parse_special' => true])->throw()->json('tokens');

        return RenderedPrompt::of(array_map('intval', $ids ?? []), $text);
    }
}
