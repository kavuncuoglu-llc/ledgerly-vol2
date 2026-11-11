<?php

namespace App\Ai\Triage;

use App\Ai\AiManager;
use App\Ai\Context\ContextBlock;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\DraftReplyPrompt;
use App\Ai\Retrieval\CitationChecker;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Structured\RepairPrompt;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

// A workflow step, not an agent: retrieve, write, check. The draft goes to a review queue; a person approves it.
final class Drafter
{
    public function __construct(private AiManager $ai, private Retriever $retriever, private CitationChecker $citations) {}

    public function draft(Ticket $ticket, User $owner, array $fields, array $findings, ?string $area = null, ?string $provider = null): Draft
    {
        $model = $this->model($provider);

        $text = $this->text($ticket);

        $chunks = $this->passages($text, $owner, $area);

        $messages = DraftReplyPrompt::messages($text, $fields, $findings, $chunks);

        $options = ['profile' => 'grounded', 'max_tokens' => 320, 'prompt_version' => DraftReplyPrompt::VERSION];

        $answer = (string) $model->chat(ChatRequest::make($messages, $options, 'triage.draft'))->content;

        $map = ContextBlock::labels($chunks);

        $report = $this->citations->check($answer, $map, requireCitations: $findings === []);   // a sentence built from findings is cited to the account, not to a passage

        if ($report->hasErrors()) {
            $answer = (string) $model->chat(ChatRequest::make(
                [...$messages, ['role' => 'assistant', 'content' => $answer], ['role' => 'user', 'content' => RepairPrompt::citations($report)]],
                $options + ['repaired' => true],
                'triage.draft',
            ))->content;

            $report = $this->citations->check($answer, $map, requireCitations: $findings === []);
        }

        return new Draft(
            text: trim($answer),
            citationsOk: ! $report->hasErrors(),
            sources: array_values(array_map(fn ($chunk, string $label) => ['label' => $label, 'url' => $chunk->url()], $report->cited, array_keys($report->cited))),
        );
    }

    private function model(?string $provider): ChatModel
    {
        return $provider === null
            ? $this->ai->for('triage.draft')
            : $this->ai->chat($provider);                                                  // an escalated draft goes to the provider the pipeline chose
    }

    private function text(Ticket $ticket): string
    {
        return trim(($ticket->subject ? $ticket->subject."\n" : '').($ticket->body ?: (string) $ticket->summary));
    }

    private function passages(string $text, User $owner, ?string $area): array
    {
        try {
            $scope = RetrievalScope::forUser($owner, $area);

            $chunks = $this->retriever->retrieve($text, $scope, 5);

            return $chunks === [] && $area !== null
                ? $this->retriever->retrieve($text, RetrievalScope::forUser($owner), 5)   // the area was a guess; widen it once
                : $chunks;
        } catch (Throwable $e) {
            Log::warning('Draft written without passages: retrieval failed', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
