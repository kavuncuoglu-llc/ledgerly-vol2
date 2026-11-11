<?php

namespace App\Ai\Conversations;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\SummarizePrompt;
use App\Models\Conversation;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class SummarizeConversation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public Conversation $conversation) {}

    public function uniqueId(): string
    {
        return (string) $this->conversation->id;
    }

    public function handle(AiManager $ai, ConversationStore $store, ToolResultCompactor $compactor, CurrentTenant $tenant): void
    {
        $tenant->run($this->conversation->tenant_id, function () use ($ai, $store, $compactor) {
            $c = $this->conversation->fresh();

            if ($c === null) {
                return;
            }

            $compactor->compact($c);                                                        // two exchanges behind the live edge

            $keepRecent = config('ai.history.keep_recent');                                 // exchanges left verbatim

            $toSummarize = $store->exchangesBetween($c, $c->summary_through_seq, $store->cutoffSeq($c, $keepRecent));

            if ($toSummarize->isEmpty()) {
                return;
            }

            try {
                $response = $ai->for('conversation.summarize')->chat(ChatRequest::make(
                    SummarizePrompt::messages(existing: $c->summary, exchanges: $toSummarize),
                    [
                        'profile' => 'deterministic',
                        'max_tokens' => config('ai.history.summary_max_tokens'),
                        'prompt_version' => SummarizePrompt::VERSION,
                        'conversation_id' => $c->id,
                    ],
                    'conversation.summarize',
                ));
            } catch (Throwable $e) {
                report($e);                                                                 // a failed summary leaves the old one in place

                return;
            }

            $summary = trim((string) $response->content);

            if ($summary === '') {
                return;
            }

            $c->update(['summary' => $summary, 'summary_through_seq' => $toSummarize->last()->lastSeq()]);
        });
    }
}
