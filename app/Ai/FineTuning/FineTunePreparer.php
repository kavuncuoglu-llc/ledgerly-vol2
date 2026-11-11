<?php

namespace App\Ai\FineTuning;

use App\Ai\Tokens\TokenCounter;
use App\Ai\Training\Dataset;
use App\Ai\Training\HostPolicy;
use App\Models\FineTuneJob;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

// Chapter 41, Topic 2: decide what may leave, convert the rows to the provider's chat-message JSON lines, validate every row against its documented rules and count the tokens, all before anything is uploaded. The file is the prompt's messages followed by the completion's messages; a row that breaks a rule stops the preparation with the line and the rule, never a silent drop.
final class FineTunePreparer
{
    private const ROLES = ['system', 'user', 'assistant', 'tool'];

    public function __construct(private HostPolicy $policy, private TokenCounter $tokens, private FineTuningClients $clients) {}

    /**
     * @param  list<string>  $sources
     * @return array{job: FineTuneJob, invalid: list<string>, excluded: array<string, int>, validation_rows: int}
     */
    public function prepare(Dataset $dataset, string $provider, array $sources, string $out, bool $validation = false): array
    {
        $this->clients->driverName($provider);

        if ($sources === []) {
            throw new InvalidArgumentException('--only names no parts of the dataset: exemplars, synthetic, replay (no customer data), production, edits.');
        }

        $refusals = $this->policy->refusals($dataset, $provider, $sources);

        if ($refusals !== []) {
            throw new HostedUploadRefused(implode(' ', $refusals));
        }

        $train = $dataset->joined('train.jsonl', 'train.meta.jsonl');

        $kept = array_values(array_filter($train, fn (array $record) => in_array($record['meta']['source'], $sources, true)));

        if ($kept === []) {
            throw new InvalidArgumentException("No row of {$dataset->name()} comes from ".implode(', ', $sources).'.');
        }

        $invalid = $this->invalid($kept);

        $excluded = $this->excluded($train, $sources);

        $tokens = array_sum(array_map(fn (array $record) => $this->tokensOf($this->messages($record)), $kept));

        $validationRows = $this->validationRecords($dataset, $sources, $validation);

        $job = FineTuneJob::query()->create([
            'provider' => $provider,
            'driver' => $this->clients->driverName($provider),
            'dataset' => $dataset->name(),
            'dataset_hash' => $dataset->hash('train.jsonl'),
            'sources' => array_values($sources),
            'row_count' => count($kept),
            'tokens_per_epoch' => $tokens,
            'status' => 'prepared',
        ]);

        if ($invalid !== []) {
            $job->delete();

            return ['job' => $job, 'invalid' => $invalid, 'excluded' => $excluded, 'validation_rows' => 0];
        }

        $path = $this->absolute($out);

        $this->write($path, $kept);

        $validationPath = null;

        if ($validationRows !== []) {
            $validationPath = preg_replace('/\.jsonl$/', '', $path).'.validation.jsonl';

            $this->write($validationPath, $validationRows);
        }

        $job->update(['file_path' => $path, 'validation_path' => $validationPath]);

        return ['job' => $job, 'invalid' => [], 'excluded' => $excluded, 'validation_rows' => count($validationRows)];
    }

    /** @return list<array> */
    private function validationRecords(Dataset $dataset, array $sources, bool $wanted): array
    {
        if (! $wanted) {
            return [];
        }

        $records = $dataset->joined('validation.jsonl', 'validation.meta.jsonl');

        return array_values(array_filter($records, fn (array $record) => in_array($record['meta']['source'], $sources, true)));
    }

    /** @return list<string> */
    private function invalid(array $records): array
    {
        $problems = [];

        foreach ($records as $line => $record) {
            foreach ($this->problems($this->messages($record)) as $problem) {
                $problems[] = 'row '.($line + 1).' ('.$record['id'].'): '.$problem;
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function problems(array $messages): array
    {
        $problems = [];

        if ($messages === []) {
            return ['no messages'];
        }

        $previous = null;

        $sawUser = false;

        foreach ($messages as $index => $message) {
            $role = $message['role'] ?? null;

            if (! in_array($role, self::ROLES, true)) {
                $problems[] = "message {$index} has role [".json_encode($role).']';

                continue;
            }

            $sawUser = $sawUser || $role === 'user';

            $problems = [...$problems, ...$this->messageProblems($message, $index, $previous)];

            $previous = $message;
        }

        if (! $sawUser) {
            $problems[] = 'no user message';
        }

        $last = end($messages);

        if (($last['role'] ?? null) !== 'assistant' || ! empty($last['tool_calls'])) {
            $problems[] = 'does not end with an assistant turn';
        }

        if ($this->tokensOf($messages) > (int) config('ai.fine_tuning.max_tokens_per_row')) {
            $problems[] = 'is over the provider\'s '.config('ai.fine_tuning.max_tokens_per_row').'-token row limit';
        }

        return $problems;
    }

    private function messageProblems(array $message, int $index, ?array $previous): array
    {
        $problems = [];

        $role = $message['role'];

        $calls = ! empty($message['tool_calls']);

        if ($role === 'system' && $index !== 0) {
            $problems[] = "a system message at position {$index}";
        }

        if (trim((string) ($message['content'] ?? '')) === '' && ! $calls) {
            $problems[] = "message {$index} ({$role}) is empty";
        }

        if ($role === 'tool' && empty($message['tool_call_id'])) {
            $problems[] = "tool message {$index} has no tool_call_id";
        }

        if ($role === 'tool' && ! in_array($previous['role'] ?? null, ['assistant', 'tool'], true)) {
            $problems[] = "tool message {$index} does not follow an assistant tool call";
        }

        if ($role === 'assistant' && $calls && ! is_string($message['tool_calls'][0]['function']['arguments'] ?? null)) {
            $problems[] = "message {$index}: tool call arguments must be a JSON string";
        }

        return $problems;
    }

    private function excluded(array $train, array $sources): array
    {
        $excluded = [];

        foreach ($train as $record) {
            if (! in_array($record['meta']['source'], $sources, true)) {
                $source = $record['meta']['source'];

                $excluded[$source] = ($excluded[$source] ?? 0) + 1;
            }
        }

        ksort($excluded);

        return $excluded;
    }

    private function messages(array $record): array
    {
        return [...$record['prompt'], ...$record['completion']];
    }

    private function tokensOf(array $messages): int
    {
        $total = 0;

        foreach ($messages as $message) {
            $total += 4 + $this->tokens->count((string) ($message['content'] ?? '').(empty($message['tool_calls']) ? '' : json_encode($message['tool_calls'])));
        }

        return $total;
    }

    private function write(string $path, array $records): void
    {
        File::ensureDirectoryExists(dirname($path));

        File::put($path, implode('', array_map(fn (array $record) => json_encode(['messages' => $this->messages($record)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", $records)));
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }
}
