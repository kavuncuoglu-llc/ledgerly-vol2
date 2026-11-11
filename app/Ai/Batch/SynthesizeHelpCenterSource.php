<?php

namespace App\Ai\Batch;

use App\Models\HelpChunk;

// Chapter 37, Topic 7: synthetic case generation (Chapter 29), as requests: one question a customer might ask for each shared help-center chunk. Large, slack and reviewed later anyway.
final class SynthesizeHelpCenterSource implements BatchSource
{
    public function requests(string $spec): iterable
    {
        $limit = preg_match('/^help-center(?::(\d+))?$/', $spec, $match) === 1 && isset($match[1])
            ? (int) $match[1]
            : PHP_INT_MAX;

        $chunks = HelpChunk::query()->whereNull('tenant_id')->where('kind', 'parent')->orderBy('id')->limit($limit)->cursor();

        foreach ($chunks as $chunk) {
            yield [
                'custom_id' => 'chunk:'.$chunk->id,
                'messages' => [
                    ['role' => 'system', 'content' => 'Write one question a customer of an invoicing product might ask that the passage below answers. Reply with the question only.'],
                    ['role' => 'user', 'content' => "Passage:\n".$chunk->content],
                ],
                'max_tokens' => 60,
            ];
        }
    }
}
