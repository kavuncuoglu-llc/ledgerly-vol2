<?php

namespace App\Ai\Local;

final class TemplateInspector
{
    public function __construct(private OllamaNative $native) {}

    public function inspect(string $model): ChatTemplate
    {
        return ChatTemplate::fromShow($model, $this->native->show($model));
    }

    // The same prompt with the chat template off (raw generate) and on (chat): same weights, one difference.
    public function experiment(string $model, string $prompt): array
    {
        $raw = $this->native->generate($model, $prompt, raw: true);
        $chat = $this->native->chat($model, [['role' => 'user', 'content' => $prompt]]);

        return [
            'raw' => (string) ($raw['response'] ?? ''),
            'chat' => (string) ($chat['message']['content'] ?? ''),
        ];
    }
}
