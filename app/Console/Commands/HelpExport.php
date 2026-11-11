<?php

namespace App\Console\Commands;

use App\Models\HelpChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class HelpExport extends Command
{
    protected $signature = 'help:export
        {--jsonl : Write JSON Lines (the only format)}
        {--to=training/data/help_chunks.jsonl : The file to write, relative to the project root}';

    protected $description = 'Write the shared help-center chunks as JSON Lines, for the Python scripts (Chapter 28)';

    public function handle(): int
    {
        $path = $this->path((string) $this->option('to'));

        File::ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'w');

        $written = 0;

        HelpChunk::query()
            ->whereNull('tenant_id')
            ->whereNotNull('embedding')
            ->orderBy('id')
            ->select(['id', 'source', 'heading', 'heading_path', 'kind', 'tokens', 'content', 'context_header', 'parent_id'])
            ->chunkById(200, function ($chunks) use ($handle, &$written) {
                foreach ($chunks as $chunk) {
                    fwrite($handle, json_encode($this->row($chunk), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

                    $written++;
                }
            });

        fclose($handle);

        if ($written === 0) {
            $this->error('help_chunks has no embedded shared chunks. Run php artisan help:ingest first.');

            return self::FAILURE;
        }

        $this->info("Wrote {$written} chunks to ".str_replace(base_path().'/', '', $path));

        return self::SUCCESS;
    }

    private function row(HelpChunk $chunk): array
    {
        return [
            'id' => $chunk->id,
            'source' => $chunk->source,
            'heading' => $chunk->heading,
            'heading_path' => $chunk->heading_path,
            'kind' => $chunk->kind,
            'tokens' => $chunk->tokens,
            'text' => $this->rerankerText($chunk),
        ];
    }

    private function rerankerText(HelpChunk $chunk): string
    {
        if ($chunk->parent_id !== null) {
            return $chunk->context_header
                ? $chunk->context_header."\n\n".$chunk->content
                : $chunk->content;                                    // a child: the text that matched, as RetrievedChunk::matchText() scores it
        }

        $firstLine = Str::before($chunk->content, "\n");

        $body = $firstLine === $chunk->heading
            ? ltrim(Str::after($chunk->content, "\n"))
            : $chunk->content;

        return $chunk->heading."\n".$body;                            // a whole section: heading and body, as RetrievedChunk::matchText() scores it
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }
}
