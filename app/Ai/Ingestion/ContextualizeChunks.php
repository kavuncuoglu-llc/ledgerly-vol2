<?php

namespace App\Ai\Ingestion;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

// Contextual retrieval: a model reads the whole article and writes a sentence or two that situates one chunk in it.
// The article leads the prompt and only the chunk changes, so a server with a prefix cache reads it once per article.
final class ContextualizeChunks
{
    public const VERSION = '2026-11-02.1';

    public function __construct(private AiManager $ai, private ?string $model = null) {}

    public function summary(string $articleBody, string $chunkText): string
    {
        try {
            $response = $this->ai->for('retrieval.context')->chat(ChatRequest::make(
                [
                    ['role' => 'system', 'content' => "<document>\n{$articleBody}\n</document>"],
                    ['role' => 'user', 'content' => "<chunk>\n{$chunkText}\n</chunk>\n\nIn one or two sentences, say where this chunk sits in the document above (which section, plan, or feature), to help search find it. Reply with that context only."],
                ],
                array_filter([
                    'profile' => 'deterministic',
                    'max_tokens' => 100,
                    'model' => $this->model,
                    'prompt_version' => self::VERSION,
                ]),
                'retrieval.context',
            ));
        } catch (Throwable $e) {
            Log::warning('Chunk context skipped: the model did not answer', ['error' => $e->getMessage()]);

            return '';
        }

        return trim(preg_replace('/\s+/', ' ', (string) $response->content));
    }
}
