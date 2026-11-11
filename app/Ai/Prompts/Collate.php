<?php

namespace App\Ai\Prompts;

// Chapter 46, and a finding of Chapter 40's template check: the assembled prompt has the retrieved passages and the question as two user messages in a row. Ollama's chat templating merges consecutive messages of one role (joined by a blank line) before it renders; the Hugging Face template
// (what training uses, and what vLLM uses) renders each as its own turn. The same prompt is then two different token sequences, and a fine-tune learns one and is served the other. A fine-tuned role is therefore always sent the merged form, and training rows are written in it:
// whichever server renders it, there is one message to render.
final class Collate
{
    /** @param list<array> $messages
     * @return list<array> */
    public static function consecutive(array $messages): array
    {
        $collated = [];

        foreach ($messages as $message) {
            $last = count($collated) - 1;

            if ($last >= 0 && self::mergeable($collated[$last], $message)) {
                $collated[$last]['content'] .= "\n\n".$message['content'];

                continue;
            }

            $collated[] = $message;
        }

        return $collated;
    }

    private static function mergeable(array $previous, array $next): bool
    {
        if (($previous['role'] ?? null) !== ($next['role'] ?? null)) {
            return false;
        }

        if (! in_array($next['role'] ?? null, ['user', 'system'], true)) {
            return false;                                                          // an assistant turn may carry tool calls, and a tool result belongs to its call: never merged
        }

        return is_string($previous['content'] ?? null) && is_string($next['content'] ?? null);
    }
}
