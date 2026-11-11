<?php

namespace App\Ai\Conversations;

use App\Ai\Retrieval\RetrievedChunk;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

interface ConversationStore
{
    public function start(User $user, string $promptVersion): Conversation;

    public function append(Conversation $c, array $message): Message;                   // counts tokens, assigns seq

    public function placeholder(Conversation $c, string $question): Message;              // Chapter 18: the empty assistant row a queued job will fill in

    public function complete(Message $reply, array $message, string $status = 'completed'): Message;   // fills the placeholder and moves it to the end of the conversation

    /** @return array<int, array> messages shaped for the API, within $budgetTokens */
    public function history(Conversation $c, int $budgetTokens): array;

    public function summarize(Conversation $c): void;                                     // Topic 4

    public function needsSummary(Conversation $c, int $allowance): bool;

    public function needsMaintenance(Conversation $c, int $allowance): bool;              // a summary is due, or an old tool result can be compacted

    /** @return Collection<int, Exchange> complete exchanges with seq above $afterSeq, up to and including $uptoSeq */
    public function exchangesBetween(Conversation $c, int $afterSeq, int $uptoSeq): Collection;

    public function cutoffSeq(Conversation $c, int $keepRecent): int;                     // last seq that may be summarized, leaving $keepRecent exchanges alone

    /** @param array<string, RetrievedChunk> $cited label => chunk */
    public function recordSources(Message $message, array $cited): void;                 // Chapter 15: which passages a reply used, for links and audit

    public function close(Conversation $c): void;
}
