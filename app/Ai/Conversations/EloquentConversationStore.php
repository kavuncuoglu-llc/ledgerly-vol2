<?php

namespace App\Ai\Conversations;

use App\Ai\Memory\ExtractMemories;
use App\Ai\Tokens\TokenCounter;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageSource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentConversationStore implements ConversationStore
{
    public function __construct(private TokenCounter $counter, private ToolResultCompactor $compactor) {}

    public function start(User $user, string $promptVersion): Conversation
    {
        if ($user->tenant_id === null) {
            throw NoTenant::forUser($user->id);
        }

        return Conversation::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'status' => 'open',
            'prompt_version' => $promptVersion,
            'summary_through_seq' => 0,
        ]);
    }

    public function append(Conversation $c, array $message): Message
    {
        return DB::transaction(function () use ($c, $message) {
            Conversation::withoutGlobalScopes()->whereKey($c->id)->lockForUpdate()->first();   // one writer at a time per conversation

            $seq = (int) Message::withoutGlobalScopes()->where('conversation_id', $c->id)->max('seq') + 1;

            $created = Message::create([
                'conversation_id' => $c->id,
                'tenant_id' => $c->tenant_id,
                'seq' => $seq,
                'role' => $message['role'],
                'content' => $message['content'] ?? null,
                'tool_calls' => $message['tool_calls'] ?? null,
                'tool_call_id' => $message['tool_call_id'] ?? null,
                'tokens' => $this->tokensOf($message),                       // counted once, at write time
                'model_call_id' => $message['model_call_id'] ?? null,
            ]);

            $c->touch();

            return $created;
        });
    }

    public function placeholder(Conversation $c, string $question): Message
    {
        return DB::transaction(function () use ($c, $question) {
            Conversation::withoutGlobalScopes()->whereKey($c->id)->lockForUpdate()->first();

            $created = Message::create([
                'conversation_id' => $c->id,
                'tenant_id' => $c->tenant_id,
                'seq' => (int) Message::withoutGlobalScopes()->where('conversation_id', $c->id)->max('seq') + 1,
                'role' => 'assistant',
                'status' => 'pending',
                'content' => null,
                'question' => $question,
                'tokens' => 0,
            ]);

            $c->touch();

            return $created;
        });
    }

    public function complete(Message $reply, array $message, string $status = 'completed'): Message
    {
        return DB::transaction(function () use ($reply, $message, $status) {
            Conversation::withoutGlobalScopes()->whereKey($reply->conversation_id)->lockForUpdate()->first();

            $reply->update([
                'seq' => (int) Message::withoutGlobalScopes()->where('conversation_id', $reply->conversation_id)->max('seq') + 1,   // the tool turns of this run were appended after the placeholder was made
                'status' => $status,
                'content' => $message['content'] ?? null,
                'tokens' => $this->tokensOf($message),
                'model_call_id' => $message['model_call_id'] ?? $reply->model_call_id,
            ]);

            return $reply;
        });
    }

    public function recordSources(Message $message, array $cited): void
    {
        foreach ($cited as $label => $chunk) {
            MessageSource::create([
                'message_id' => $message->id,
                'tenant_id' => $message->tenant_id,
                'help_chunk_id' => $chunk->id,
                'chunk_key' => $chunk->chunkKey,
                'label' => $label,
                'source' => $chunk->source,
                'heading_path' => $chunk->headingPath,
                'url' => $chunk->url(),
                'help_version' => $chunk->helpVersion,
            ]);
        }
    }

    public function history(Conversation $c, int $budgetTokens): array
    {
        $all = $this->exchanges($c);

        $pinned = $this->pinned($all);

        $live = $all
            ->filter(fn (Exchange $exchange) => $exchange->firstSeq() > (int) $c->summary_through_seq)
            ->reject(fn (Exchange $exchange) => $exchange === $pinned);

        $remaining = $budgetTokens - $this->summaryTokens($c) - ($pinned?->tokens() ?? 0);

        $kept = [];

        foreach ($live->reverse() as $exchange) {                                     // newest first, whole exchanges only
            if ($exchange->tokens() > $remaining) {
                break;
            }

            array_unshift($kept, $exchange);                                          // keep chronological order

            $remaining -= $exchange->tokens();
        }

        if ($pinned) {
            array_unshift($kept, $pinned);
        }

        return collect($kept)->flatMap(fn (Exchange $exchange) => $exchange->toApiMessages())->all();
    }

    public function summarize(Conversation $c): void
    {
        SummarizeConversation::dispatch($c);
    }

    public function needsSummary(Conversation $c, int $allowance): bool
    {
        return $this->unsummarizedTokens($c) + $this->fixedHistoryTokens($c) > $allowance;      // history() would now have to drop something
    }

    public function needsMaintenance(Conversation $c, int $allowance): bool
    {
        if ($this->needsSummary($c, $allowance)) {
            return true;
        }

        return $this->compactor->hasWork($c);
    }

    public function exchangesBetween(Conversation $c, int $afterSeq, int $uptoSeq): Collection
    {
        $all = $this->exchanges($c);

        $pinned = $this->pinned($all);

        return $all
            ->reject(fn (Exchange $exchange) => $exchange === $pinned)                // verbatim already; the summary need not repeat it
            ->filter(fn (Exchange $exchange) => $exchange->firstSeq() > $afterSeq && $exchange->lastSeq() <= $uptoSeq)
            ->values();
    }

    public function cutoffSeq(Conversation $c, int $keepRecent): int
    {
        $all = $this->exchanges($c);

        if ($all->count() <= $keepRecent) {
            return (int) $c->summary_through_seq;
        }

        return $all[$all->count() - $keepRecent - 1]->lastSeq();
    }

    public function close(Conversation $c): void
    {
        if ($c->status !== 'open') {
            return;
        }

        $c->update(['status' => 'closed']);

        ExtractMemories::dispatch($c);
    }

    private function exchanges(Conversation $c): Collection
    {
        return Exchanges::complete($c->messages()->get());
    }

    private function pinned(Collection $exchanges): ?Exchange
    {
        if (! config('ai.history.pin_first')) {
            return null;
        }

        return $exchanges->first();
    }

    private function unsummarizedTokens(Conversation $c): int
    {
        $all = $this->exchanges($c);

        $pinned = $this->pinned($all);

        return $all
            ->reject(fn (Exchange $exchange) => $exchange === $pinned)
            ->filter(fn (Exchange $exchange) => $exchange->firstSeq() > (int) $c->summary_through_seq)
            ->sum(fn (Exchange $exchange) => $exchange->tokens());
    }

    private function fixedHistoryTokens(Conversation $c): int
    {
        return $this->summaryTokens($c) + ($this->pinned($this->exchanges($c))?->tokens() ?? 0);
    }

    private function summaryTokens(Conversation $c): int
    {
        if (! $c->summary) {
            return 0;
        }

        return $this->counter->count(SummaryNote::render($c->summary));
    }

    private function tokensOf(array $message): int
    {
        $text = (string) ($message['content'] ?? '');

        if (! empty($message['tool_calls'])) {
            $text .= json_encode($message['tool_calls'], JSON_UNESCAPED_SLASHES);
        }

        return $this->counter->count($text);
    }
}
