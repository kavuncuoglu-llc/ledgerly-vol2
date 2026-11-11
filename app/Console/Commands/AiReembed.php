<?php

namespace App\Console\Commands;

use App\Ai\Python\ContractViolation;
use App\Ai\Python\PythonTasks;
use App\Models\HelpChunk;
use Illuminate\Console\Command;

class AiReembed extends Command
{
    protected $signature = 'ai:reembed
        {--via=python : Where the vectors are made (only python: Volume 1\'s Ollama path is help:ingest --force)}
        {--model= : The Hub repo the Python worker serves (default: config ai.python.embed_model)}
        {--batch=64 : Chunks per task (the contract allows 256)}
        {--into=shadow : Where the vectors land (only shadow: help_chunk_vectors, never the live column)}
        {--limit= : Only the first N chunks, for a quick trial}';

    protected $description = 'Queue the help center for the Python embedding worker, in batches, into the shadow table (Chapter 27)';

    public function handle(): int
    {
        if ($this->option('via') !== 'python') {
            $this->error('--via=python is the only path here. Volume 1\'s Ollama path is: php artisan help:ingest --force');

            return self::FAILURE;
        }

        if ($this->option('into') !== 'shadow') {
            $this->error('--into=shadow is the only target: ai:promote-vectors moves vectors into help_chunks after you have compared them.');

            return self::FAILURE;
        }

        $batch = (int) $this->option('batch');

        if ($batch < 1 || $batch > 256) {
            $this->error('--batch must be between 1 and 256 (maxItems in contracts/embed-batch.v1.json).');

            return self::FAILURE;
        }

        $ids = $this->chunkIds();

        if ($ids === []) {
            $this->error('help_chunks has no embedded chunks. Run php artisan help:ingest first.');

            return self::FAILURE;
        }

        return $this->queue($ids, $batch, $this->model());
    }

    private function queue(array $ids, int $batch, string $model): int
    {
        $taskIds = [];

        try {
            foreach (array_chunk($ids, $batch) as $batchIds) {
                $taskIds[] = PythonTasks::embed($batchIds, $model);
            }
        } catch (ContractViolation $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Queued %d task(s) of up to %d chunks (%d chunks) for %s into help_chunk_vectors.', count($taskIds), $batch, count($ids), $model));
        $this->line('Watch them with: php artisan py:status   (py:listen must be running to apply the results)');

        return self::SUCCESS;
    }

    private function chunkIds(): array
    {
        $query = HelpChunk::query()
            ->whereNotNull('embedding')
            ->orderBy('id');

        if ($this->option('limit') !== null) {
            $query->limit((int) $this->option('limit'));
        }

        return $query->pluck('id')->all();
    }

    private function model(): string
    {
        return trim((string) $this->option('model')) ?: (string) config('ai.python.embed_model');
    }
}
