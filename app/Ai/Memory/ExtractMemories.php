<?php

namespace App\Ai\Memory;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\ExtractMemoriesPrompt;
use App\Models\Conversation;
use App\Models\UserMemory;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

final class ExtractMemories implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public Conversation $conversation) {}

    public function handle(AiManager $ai, CurrentTenant $tenant): int
    {
        return $tenant->run($this->conversation->tenant_id, fn () => $this->extract($ai));
    }

    private function extract(AiManager $ai): int
    {
        $c = $this->conversation->fresh();

        if ($c === null) {
            return 0;
        }

        $messages = $c->messages()->get();

        $said = $messages->where('role', 'user')->pluck('content')->implode(' ');

        if ($said === '') {
            return 0;
        }

        try {
            $response = $ai->for('conversation.memories')->chat(ChatRequest::make(
                ExtractMemoriesPrompt::messages($messages),
                ['profile' => 'deterministic', 'max_tokens' => 300, 'prompt_version' => ExtractMemoriesPrompt::VERSION, 'conversation_id' => $c->id],
                'conversation.memories',
            ));
        } catch (Throwable $e) {
            report($e);                                                       // no facts is a fine outcome

            return 0;
        }

        $stored = 0;

        foreach ($this->parse((string) $response->content) as $fact) {
            if (! $this->isStated($fact['value'], $said)) {
                continue;
            }

            $stored += (int) $this->remember($c, $fact);
        }

        return $stored;
    }

    private function remember(Conversation $c, array $fact): bool
    {
        $existing = UserMemory::query()->where('user_id', $c->user_id)->where('key', $fact['key'])->first();

        if ($existing?->source === 'stated') {
            return false;                                                     // what the customer told us outranks what we extracted
        }

        UserMemory::updateOrCreate(
            ['user_id' => $c->user_id, 'key' => $fact['key']],
            ['tenant_id' => $c->tenant_id, 'value' => $fact['value'], 'source' => 'extracted', 'conversation_id' => $c->id],
        );

        return true;
    }

    /** @return array<int, array{key: string, value: string}> */
    private function parse(string $content): array
    {
        $json = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content)));

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $facts = [];

        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = Str::snake(trim((string) ($item['key'] ?? '')));
            $value = trim((string) ($item['value'] ?? ''));

            if ($key === '' || $value === '') {
                continue;
            }

            if ((float) ($item['confidence'] ?? 0) < config('ai.memory.min_confidence')) {
                continue;
            }

            $facts[] = ['key' => Str::limit($key, 60, ''), 'value' => Str::limit($value, 300, '')];
        }

        return $facts;
    }

    private function isStated(string $value, string $said): bool
    {
        $words = collect(preg_split('/[^\p{L}\p{N}@.]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $word) => mb_strlen($word) >= 3)
            ->unique();

        if ($words->isEmpty()) {
            return false;
        }

        $spoken = mb_strtolower($said);

        $found = $words->filter(fn (string $word) => str_contains($spoken, $word))->count();

        return $found / $words->count() >= 0.5;                               // stated, not inferred: most of the value is in the customer's own words
    }
}
