<?php

namespace App\Ai\Training\TemplateCheck;

use App\Ai\Local\OllamaNative;
use RuntimeException;

// Ollama applies the template from the model's own file and says how many prompt tokens it evaluated, not which. A doubled start token or a missing system block still shows as a count that differs by one or more.
final class OllamaTemplateRenderer implements TemplateRenderer
{
    public function __construct(private OllamaNative $ollama, private string $model) {}

    public function name(): string
    {
        return "ollama ({$this->model})";
    }

    public function render(array $messages): RenderedPrompt
    {
        $reply = $this->ollama->chat($this->model, $this->withObjectArguments($messages), ['num_predict' => 1]);

        if (! isset($reply['prompt_eval_count'])) {
            throw new RuntimeException('Ollama did not report prompt_eval_count.');
        }

        return new RenderedPrompt((int) $reply['prompt_eval_count']);
    }

    // Ollama's chat API takes a tool call's arguments as an object; the OpenAI shape stores them as a JSON string.
    private function withObjectArguments(array $messages): array
    {
        return array_map(function (array $message) {
            foreach ($message['tool_calls'] ?? [] as $i => $call) {
                if (is_string($call['function']['arguments'] ?? null)) {
                    $message['tool_calls'][$i]['function']['arguments'] = json_decode($call['function']['arguments'], true);
                }
            }

            return $message;
        }, $messages);
    }
}
