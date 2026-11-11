<?php

namespace App\Ai\Context;

use App\Ai\Retrieval\RetrievedChunk;

// How retrieved passages are written into the window: a one-line preamble, then each passage under a short bracketed label the model cites and the checker verifies.
final class ContextBlock
{
    public static function render(array $chunks): string
    {
        if ($chunks === []) {
            return "Context:\n(no context provided)";            // the escape hatch needs to see that nothing was provided
        }

        $version = $chunks[0]->helpVersion;

        $passages = [];

        foreach (self::labels($chunks) as $label => $chunk) {
            $passages[] = "[{$label}]\n".$chunk->body();
        }

        return implode("\n\n", [
            "The following passages are from Ledgerly's help center (version {$version}). Answer only from them.\n"
            ."Cite the label of each passage you rely on, in brackets, after the sentence it supports.\n"
            ."If they don't contain the answer, say so.",
            ...$passages,
        ]);
    }

    /**
     * @param  RetrievedChunk[]  $chunks
     * @return array<string, RetrievedChunk> label => chunk, in the order the passages appear
     */
    public static function labels(array $chunks): array
    {
        $labels = [];

        foreach ($chunks as $chunk) {
            $label = $chunk->label();

            $suffix = 2;

            while (isset($labels[$label])) {
                $label = $chunk->label().' #'.$suffix++;               // two passages from one section stay distinguishable
            }

            $labels[$label] = $chunk;
        }

        return $labels;
    }
}
