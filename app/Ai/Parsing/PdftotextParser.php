<?php

namespace App\Ai\Parsing;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// The no-sidecar fallback for a born-digital PDF: reading order and paragraphs, no headings and no tables
class PdftotextParser implements DocumentParser
{
    public function supports(string $extension): bool
    {
        return $extension === 'pdf';
    }

    public function available(): bool
    {
        return (new ExecutableFinder)->find('pdftotext') !== null;
    }

    public function parse(string $path): string
    {
        if (! $this->available()) {
            throw new ParsingFailed('pdftotext (Poppler) is not installed, and the sidecar is not reachable.');
        }

        $process = new Process(['pdftotext', '-layout', $path, '-']);

        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ParsingFailed("pdftotext failed on {$path}: ".trim($process->getErrorOutput()));
        }

        $text = preg_replace("/[ \t]+\n/", "\n", $process->getOutput());

        return trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\f", "\n\n", $text)))."\n";
    }
}
