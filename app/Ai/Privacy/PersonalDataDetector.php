<?php

namespace App\Ai\Privacy;

// Chapter 34, Topic 4: validated patterns in PHP, which cannot be down, and names and addresses from the NER service, which can. Everything the app's own identifier formats cover is left alone.
final class PersonalDataDetector
{
    /** @param Detector[] $detectors in priority order: where two overlap, the first one keeps the text */
    public function __construct(
        private array $detectors,
        private AllowList $allowList,
        private Ner $ner,
    ) {}

    public function nerAvailable(): bool
    {
        return $this->ner->available();
    }

    /**
     * @return Span[] in text order
     *
     * @throws NerUnavailable
     */
    public function find(string $text): array
    {
        $patterns = $this->findPatterns($text);

        $names = $this->allowList->filter($text, array_filter(
            $this->ner->find($text),
            fn (Span $span) => ! $this->allowList->allowsTerm($span->text),
        ));

        return $this->resolved([...$patterns, ...$names]);
    }

    /** @return Span[] in text order */
    public function findPatterns(string $text): array
    {
        $found = [];

        foreach ($this->detectors as $detector) {
            array_push($found, ...$detector->find($text));
        }

        return $this->resolved($this->allowList->filter($text, $found));
    }

    // Irreversible, for logs, error reports and evaluation data: "[email]", "[card]". Patterns only, so it never waits on a service.
    public function mask(string $text): string
    {
        foreach (array_reverse($this->findPatterns($text)) as $span) {
            $text = substr_replace($text, '['.strtolower($span->type).']', $span->offset, $span->length);
        }

        return $text;
    }

    // The same, with names and addresses when the service is there. For evaluation cases and exports, where a person can wait.
    public function maskWithNames(string $text): string
    {
        $spans = $this->ner->available()
            ? $this->find($text)
            : $this->findPatterns($text);

        foreach (array_reverse($spans) as $span) {
            $text = substr_replace($text, '['.strtolower($span->type).']', $span->offset, $span->length);
        }

        return $text;
    }

    /**
     * @param  Span[]  $spans  earlier spans win an overlap
     * @return Span[]
     */
    private function resolved(array $spans): array
    {
        $kept = [];

        foreach ($spans as $span) {
            foreach ($kept as $other) {
                if ($span->overlaps($other)) {
                    continue 2;
                }
            }

            $kept[] = $span;
        }

        usort($kept, fn (Span $a, Span $b) => $a->offset <=> $b->offset);

        return $kept;
    }
}
