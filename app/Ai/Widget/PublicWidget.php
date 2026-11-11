<?php

namespace App\Ai\Widget;

use App\Ai\AiManager;
use App\Ai\Budgets\BudgetExhausted;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\FinishReason;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Schemas\Category;
use App\Ai\Security\AnswerRenderer;
use App\Ai\Security\ExtractionGuard;
use App\Ai\Support\ClassifyTicket;
use Illuminate\Support\Str;
use Throwable;

// Chapter 35, Topic 7: "Ask Ledgerly" on the marketing site, run as a separate, cheap product. The `local` role only (the feature says so twice: its provider and allowed_providers), a short max_tokens, a classifier that sends
// off-topic questions to a fixed template before anything is generated, shared help-center passages only, its own budgets, and search results when the budget is spent. Nothing here knows a tenant, a customer or an invoice.
final class PublicWidget
{
    public const FEATURE = 'widget.ask';

    public function __construct(
        private AiManager $ai,
        private Retriever $retriever,
        private ClassifyTicket $classify,
        private WidgetBudget $budget,
        private ExtractionGuard $extraction,
        private AnswerRenderer $renderer,
    ) {}

    public function ask(string $question, string $session): WidgetAnswer
    {
        if (! $this->budget->sessionHasRoom($session)) {
            return new WidgetAnswer(WidgetAnswer::TEMPLATE, SupportPrompt::widgetSessionLimitMessage());
        }

        $chunks = $this->passages($question);

        if (! $this->budget->dayHasRoom()) {
            return $this->searchResults($chunks);                                  // the daily anonymous budget is spent: help-center results, no generation
        }

        if ($this->isOffTopic($question)) {
            return new WidgetAnswer(WidgetAnswer::TEMPLATE, SupportPrompt::declineMessage());      // a fixed template: nothing is generated for a question that is not about Ledgerly
        }

        if ($chunks === []) {
            return new WidgetAnswer(WidgetAnswer::TEMPLATE, SupportPrompt::escapeHatch());
        }

        return $this->generate($question, $session, $chunks);
    }

    private function generate(string $question, string $session, array $chunks): WidgetAnswer
    {
        $prompt = SupportPrompt::assemble(question: $question, contextChunks: $chunks);

        $request = ChatRequest::make($prompt->messages, ['profile' => 'grounded', 'max_tokens' => (int) config('ai.widget.max_tokens', 150)], self::FEATURE);

        try {
            $response = $this->ai->for(self::FEATURE)->chat($request);
        } catch (BudgetExhausted) {
            return $this->searchResults($chunks);                                  // Chapter 36: the anonymous budget, a breaker or the kill switch: search results, not an error
        } catch (Throwable $e) {
            report($e);

            return $this->searchResults($chunks);
        }

        $this->budget->spend($session, $response->usage->total());

        if ($this->extraction->check((string) $response->content, $request) !== null) {
            return new WidgetAnswer(WidgetAnswer::BLOCKED, SupportPrompt::blockedMessage());
        }

        return new WidgetAnswer(WidgetAnswer::ANSWER, $this->renderer->render($this->wholeSentences($response->content, $response->finishReason)), $this->sourcesOf($chunks));
    }

    private function isOffTopic(string $question): bool
    {
        try {
            return ($this->classify)($question) === Category::Other;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function passages(string $question): array
    {
        try {
            return $this->retriever->retrieve($question, RetrievalScope::shared(), (int) config('ai.widget.passages', 3));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    private function searchResults(array $chunks): WidgetAnswer
    {
        return new WidgetAnswer(WidgetAnswer::SEARCH, SupportPrompt::widgetSearchMessage(), $this->sourcesOf($chunks));
    }

    private function sourcesOf(array $chunks): array
    {
        return collect($chunks)
            ->unique(fn ($chunk) => $chunk->url())
            ->map(fn ($chunk) => ['label' => $chunk->title.' › '.$chunk->section(), 'url' => $chunk->url()])
            ->values()
            ->all();
    }

    // A short max_tokens can end an answer mid-sentence: show the sentences that finished.
    private function wholeSentences(?string $text, FinishReason $finish): string
    {
        $text = trim((string) $text);

        if ($finish !== FinishReason::Length) {
            return $text;
        }

        $finished = Str::beforeLast($text, '.');

        return $finished === $text || $finished === ''
            ? $text
            : $finished.'.';
    }
}
