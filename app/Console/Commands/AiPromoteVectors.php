<?php

namespace App\Console\Commands;

use App\Models\HelpChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiPromoteVectors extends Command
{
    protected $signature = 'ai:promote-vectors
        {--model= : The Hub repo whose shadow vectors replace the live ones (default: config ai.python.embed_model)}';

    protected $description = 'Swap the shadow vectors into help_chunks.embedding, once ai:compare-vectors and retrieval:eval looked right (Chapter 27)';

    public function handle(): int
    {
        $model = (string) ($this->option('model') ?: config('ai.python.embed_model'));

        $missing = $this->missing($model);

        if ($missing > 0) {
            $this->error("{$missing} embedded chunk(s) have no shadow vector for {$model}; promoting now would mix two models in one column. Run ai:reembed again.");

            return self::FAILURE;
        }

        $promoted = DB::transaction(fn () => DB::affectingStatement(
            'UPDATE help_chunks SET embedding = v.embedding, updated_at = now()
             FROM help_chunk_vectors v
             WHERE v.help_chunk_id = help_chunks.id AND v.model = ? AND help_chunks.embedding IS NOT NULL',
            [$model],
        ));

        $this->info("Promoted {$promoted} vector(s) from {$model} into help_chunks.embedding.");
        $this->line('Questions are still embedded by AI_EMBED_MODEL (Ollama), which is the same model family: that is what ai:compare-vectors and retrieval:eval measured.');

        return self::SUCCESS;
    }

    private function missing(string $model): int
    {
        return HelpChunk::query()
            ->whereNotNull('embedding')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('help_chunk_vectors')
                ->whereColumn('help_chunk_vectors.help_chunk_id', 'help_chunks.id')
                ->where('help_chunk_vectors.model', $model))
            ->count();
    }
}
