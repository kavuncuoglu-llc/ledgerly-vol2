<?php

namespace App\Ai\Training\TemplateCheck;

use Illuminate\Support\Facades\Http;

// vLLM's /tokenize takes the messages itself and applies the model's chat template.
final class VllmTemplateRenderer implements TemplateRenderer
{
    public function __construct(private string $baseUrl, private string $model, private ?string $apiKey = null) {}

    public function name(): string
    {
        return "vllm ({$this->model})";
    }

    public function render(array $messages): RenderedPrompt
    {
        $http = Http::baseUrl(rtrim($this->baseUrl, '/'))->acceptJson()->timeout(60);

        if ($this->apiKey) {
            $http = $http->withToken($this->apiKey);
        }

        $ids = $http->post('/tokenize', ['model' => $this->model, 'messages' => $messages, 'add_generation_prompt' => true, 'add_special_tokens' => false])->throw()->json('tokens');

        return RenderedPrompt::of(array_map('intval', $ids ?? []));
    }
}
