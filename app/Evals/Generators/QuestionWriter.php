<?php

namespace App\Evals\Generators;

use App\Ai\Contracts\ChatRequest;
use App\Facades\Ai;
use RuntimeException;

// Chapter 29, Topic 7: a model writes the wording, never the facts. Which provider writes is the caller's choice (--writer); a different family from the model under test finds harder questions.
class QuestionWriter
{
    public function __construct(private string $provider) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function ask(string $instruction, string $situation): string
    {
        $request = ChatRequest::make(
            [
                ['role' => 'system', 'content' => 'You write one realistic question that a small-business customer types into the support chat of an invoicing product. Reply with the question only: one or two sentences, no quotation marks, no preamble.'],
                ['role' => 'user', 'content' => $instruction."\n\n".$situation],
            ],
            ['profile' => 'creative', 'max_tokens' => 80],
            'eval.synthesize',
        );

        $text = trim((string) Ai::chat($this->provider)->chat($request)->content);

        $text = trim($text, " \t\n\r\0\x0B\"'");

        if ($text === '') {
            throw new RuntimeException("The '{$this->provider}' provider wrote an empty question.");
        }

        return $text;
    }
}
