<?php

namespace App\Console\Commands;

use App\Ai\Embeddings\ChunkSearch;
use App\Ai\Embeddings\EmbeddingFailed;
use App\Models\HelpChunk;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class HelpSearch extends Command
{
    protected $signature = 'help:search {question : What a customer might ask} {--limit=5 : How many chunks to show}';

    protected $description = 'Embed a question and print the nearest help center chunks by cosine similarity';

    public function handle(ChunkSearch $search): int
    {
        if (! HelpChunk::query()->exists()) {
            $this->error('help_chunks is empty. Run php artisan help:embed first.');

            return self::FAILURE;
        }

        try {
            $results = $search->search($this->argument('question'), (int) $this->option('limit'));
        } catch (ConnectionException|RequestException|EmbeddingFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $result) {
            $this->line(sprintf('%.2f  %s › %s', $result->similarity, $result->source, $result->heading));
        }

        return self::SUCCESS;
    }
}
