<?php

namespace App\Console\Commands;

use App\Ai\Context\ContextAssembler;
use App\Ai\Privacy\PersonalDataDetector;
use App\Ai\Prompts\Collate;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Training\SurrogateRedactor;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ExportTrainingSet extends Command
{
    protected $signature = 'dataset:export
        {category : The category to export (billing)}
        {--since= : Only conversations updated on or after this date}
        {--out=storage/datasets/export : Where train.jsonl and manifest.jsonl are written}
        {--tenant= : One tenant, by id or name: refused if its training-use setting is off}
        {--region= : The region this export runs in: a tenant with a data residency elsewhere is left out}
        {--patterns-only : Replace cards, emails, phones, IBANs and SSNs only (names and addresses need the privacy profile)}';

    protected $description = 'Rebuild every eligible answered turn exactly as it will be served, redact it with surrogates and write prompt-and-completion rows (Chapter 40)';

    public function handle(ContextAssembler $assembler, SurrogateRedactor $redactor, PersonalDataDetector $pii): int
    {
        try {
            $key = $this->hashKey();

            $tenant = $this->tenant();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $pii->nerAvailable() && ! $this->option('patterns-only')) {
            $this->error('Names and addresses cannot be screened here (no PRESIDIO_URL): start the privacy profile, or pass --patterns-only and say so in the dataset card.');

            return self::FAILURE;
        }

        $out = $this->outDirectory();

        File::ensureDirectoryExists($out);

        $rows = fopen($out.'/train.jsonl', 'w');

        $meta = fopen($out.'/manifest.jsonl', 'w');

        $written = 0;

        $this->conversations($tenant)
            ->lazyById(500)
            ->each(function (Conversation $conversation) use ($assembler, $redactor, $pii, $rows, $meta, $key, &$written) {
                foreach ($conversation->answeredTurns() as $turn) {
                    if ($turn->category !== $this->argument('category')) {
                        continue;
                    }

                    $this->writeExample($turn, $conversation, $assembler, $redactor, $pii, $rows, $meta, $key);

                    $written++;
                }
            });

        fclose($rows);

        fclose($meta);

        File::put($out.'/export.json', json_encode([
            'category' => $this->argument('category'),
            'since' => $this->option('since'),
            'tenant' => $tenant?->id === null ? null : hash_hmac('sha256', 'tenant:'.$tenant->id, $key),
            'region' => $this->option('region') ?: null,
            'patterns_only' => (bool) $this->option('patterns-only'),
            'system_version' => SupportPrompt::FINE_TUNED_VERSION,
            'examples' => $written,
            'exported_at' => now()->toDateString(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info("{$written} examples to {$out}/train.jsonl, their metadata to {$out}/manifest.jsonl.");

        return self::SUCCESS;
    }

    private function writeExample(Message $turn, Conversation $conversation, ContextAssembler $assembler, SurrogateRedactor $redactor, PersonalDataDetector $pii, $rows, $meta, string $key): void
    {
        $map = [];

        $redactor->startExample(hash_hmac('sha256', (string) $conversation->id, $key).':'.$turn->seq);

        $prompt = Collate::consecutive($assembler->rebuild($turn, systemVersion: SupportPrompt::FINE_TUNED_VERSION));      // the form a fine-tuned role is sent (Chapter 46)

        $prompt = array_map(fn ($message) => $this->redacted($message, $redactor, $pii, $map), $prompt);

        $target = $turn->agentEditedReply() ?? $turn->sentReply();

        $row = ['prompt' => $prompt, 'completion' => [['role' => 'assistant', 'content' => $redactor->redact($target, $this->spans($pii, $target), $map)]]];

        fwrite($rows, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        fwrite($meta, json_encode([
            'id' => substr(hash('sha256', json_encode($row)), 0, 16),
            'source' => $turn->targetSource(),
            'category' => $turn->category,
            'subcategory' => $this->subcategory($turn),
            'conversation' => hash_hmac('sha256', (string) $conversation->id, $key),
            'turn' => $turn->seq,
            'tenant' => hash_hmac('sha256', 'tenant:'.$conversation->tenant_id, $key),
            'user' => hash_hmac('sha256', 'user:'.$conversation->user_id, $key),
            'consent' => $conversation->tenant->trainingConsentBasis(),
            'system_version' => SupportPrompt::FINE_TUNED_VERSION,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function redacted(array $message, SurrogateRedactor $redactor, PersonalDataDetector $pii, array &$map): array
    {
        if (($message['role'] ?? '') === 'system') {
            return $message;
        }

        if (! is_string($message['content'] ?? null)) {
            return $message;
        }

        return [...$message, 'content' => $redactor->redact($message['content'], $this->spans($pii, $message['content']), $map)];
    }

    private function spans(PersonalDataDetector $pii, string $text): array
    {
        return $this->option('patterns-only')
            ? $pii->findPatterns($text)
            : $pii->find($text);
    }

    private function conversations(?Tenant $tenant)
    {
        $query = Conversation::query()
            ->eligibleForTraining($this->option('region') ?: null)              // tenant opt-in, user not excluded, region matches
            ->withPositiveSignal()
            ->with('tenant');

        if ($tenant !== null) {
            $query->where('tenant_id', $tenant->id);
        }

        if ($this->option('since')) {
            $query->where('updated_at', '>=', CarbonImmutable::parse((string) $this->option('since'))->startOfDay());
        }

        return $query;
    }

    private function tenant(): ?Tenant
    {
        $asked = $this->option('tenant');

        if ($asked === null) {
            return null;
        }

        $tenant = Tenant::query()->where('id', is_numeric($asked) ? (int) $asked : 0)->orWhere('name', $asked)->first();

        if ($tenant === null) {
            throw new InvalidArgumentException("No tenant [{$asked}].");
        }

        if (! $tenant->training_use) {
            throw new InvalidArgumentException("Tenant [{$tenant->name}] has not turned training use on: its conversations are not exported, even by name.");
        }

        return $tenant;
    }

    private function hashKey(): string
    {
        $key = (string) config('ai.training.hash_key');

        if ($key === '') {
            throw new InvalidArgumentException('Set AI_TRAINING_HASH_KEY: the source of every example is recorded as a keyed hash, so an erasure request can find it and nobody else can.');
        }

        return $key;
    }

    private function outDirectory(): string
    {
        $out = (string) $this->option('out');

        return str_starts_with($out, '/')
            ? $out
            : base_path($out);
    }

    private function subcategory(Message $turn): string
    {
        $label = (string) collect($turn->retrieved ?? [])->pluck('eval_label')->first();

        if ($label === '') {
            return 'general';
        }

        return Str::before(Str::afterLast($label, '/'), '#');
    }
}
