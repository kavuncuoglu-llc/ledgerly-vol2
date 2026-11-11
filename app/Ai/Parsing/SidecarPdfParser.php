<?php

namespace App\Ai\Parsing;

use App\Ai\Sidecar\SidecarClient;
use App\Ai\Sidecar\SidecarRejected;
use App\Ai\Sidecar\SidecarUnavailable;
use Illuminate\Support\Facades\Log;

final class SidecarPdfParser implements DocumentParser
{
    public function __construct(private SidecarClient $sidecar, private PdftotextParser $fallback) {}

    public function supports(string $extension): bool
    {
        return $extension === 'pdf';
    }

    public function parse(string $path): string
    {
        try {
            $markdown = $this->sidecar->upload('/parse', basename($path), (string) file_get_contents($path), (float) config('ai.sidecar.parse_timeout', 120))['markdown'] ?? null;
        } catch (SidecarUnavailable $e) {
            Log::warning('Sidecar PDF parsing unavailable; falling back to pdftotext', ['error' => $e->getMessage()]);

            return $this->fallback->parse($path);                                    // headings and tables are lost, the text is not
        } catch (SidecarRejected $e) {
            throw new ParsingFailed("The sidecar could not parse {$path}: ".$e->getMessage(), previous: $e);
        }

        if (! is_string($markdown) || trim($markdown) === '') {
            throw new ParsingFailed("The sidecar returned no Markdown for {$path}.");
        }

        return $markdown;
    }
}
