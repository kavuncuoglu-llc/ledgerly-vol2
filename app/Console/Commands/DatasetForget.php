<?php

namespace App\Console\Commands;

use App\Ai\Training\Dataset;
use App\Ai\Training\DatasetEraser;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DatasetForget extends Command
{
    protected $signature = 'dataset:forget
        {dataset : The published version that holds the examples (billing-v1)}
        {--conversation=* : A conversation hash from the dataset\'s metadata, or the conversation\'s id to hash with AI_TRAINING_HASH_KEY}
        {--to= : The new version\'s name (default: the next number)}
        {--reason=erasure request : Why, for both manifests}';

    protected $description = 'Build a new dataset version without the examples of some conversations and schedule the old one for retirement (Chapter 40, Chapter 34)';

    public function handle(): int
    {
        try {
            $from = Dataset::named((string) $this->argument('dataset'));

            $to = Dataset::named((string) ($this->option('to') ?: $this->next($from->name())));

            $result = (new DatasetEraser((int) config('ai.training.retire_after_days')))->forget($from, $to, $this->hashes(), (string) $this->option('reason'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$to->name()}: {$result['manifest']['counts']['total']} examples, {$result['removed']} removed.");

        $this->line("{$from->name()} is scheduled for retirement: delete it after {$result['retire_after']}. Models trained on it are retrained from {$to->name()} (Chapter 44).");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function hashes(): array
    {
        $key = (string) config('ai.training.hash_key');

        return array_map(function (string $given) use ($key) {
            if (preg_match('/^[0-9a-f]{64}$/', $given) === 1) {
                return $given;
            }

            if ($key === '') {
                throw new InvalidArgumentException('Set AI_TRAINING_HASH_KEY to forget a conversation by its id.');
            }

            return hash_hmac('sha256', $given, $key);
        }, (array) $this->option('conversation'));
    }

    private function next(string $name): string
    {
        if (preg_match('/^(.*-v)(\d+)$/', $name, $match) !== 1) {
            throw new InvalidArgumentException("Give the new version a name with --to: [{$name}] does not end in -v<number>.");
        }

        return $match[1].((int) $match[2] + 1);
    }
}
