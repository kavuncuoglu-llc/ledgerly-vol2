<?php

namespace App\Ai\Support;

use App\Ai\Cache\CachePolicy;
use App\Ai\Cache\SemanticCache;
use App\Ai\Context\AssembledPrompt;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Usage;
use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\HistoryBudget;
use App\Ai\Conversations\SummarizeConversation;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\CircuitOpen;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Health\ProviderHealth;
use App\Ai\Memory\MemoryBlock;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Retrieval\CitationChecker;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Schemas\Category;
use App\Ai\Streaming\DeltaBatcher;
use App\Ai\Streaming\StreamListener;
use App\Ai\Structured\RepairPrompt;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolRunner;
use App\Ai\Tools\ToolRunResult;
use App\Models\CachedAnswer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class SupportAssistant
{
    public function __construct(
        private ToolRunner $runner,
        private ConversationStore $store,
        private HistoryBudget $budget,
        private SemanticCache $cache,
        private CachePolicy $policy,
        private ClassifyTicket $classify,
        private Retriever $retriever,
        private CitationChecker $citations,
        private Router $router,
        private ToolRegistry $registry,
        private ProviderHealth $health,
    ) {}

    public function answerStreaming(Conversation $c, string $question, Closure $onDelta, ?Closure $onToolStart = null, ?Message $reply = null): ToolRunResult
    {
        $batcher = new DeltaBatcher($onDelta);                                     // Fig 18-4: 50 ms or 20 tokens, whichever comes first

        $result = $this->answer(
            $c->user,
            $question,
            $c,
            reply: $reply,
            stream: new StreamListener(fn (string $delta) => $batcher->push($delta), $onToolStart),
        );

        $batcher->flushNow();

        return $result;
    }

    public function answer(User $user, string $question, ?Conversation $conversation = null, array $contextChunks = [], ?Message $reply = null, ?StreamListener $stream = null): ToolRunResult
    {
        $conversation ??= $this->store->start($user, SupportPrompt::VERSION);

        if ($conversation->user_id !== $user->id) {
            throw new AuthorizationException('That conversation belongs to someone else.');
        }

        $allowance = $this->budget->historyAllowance(SupportPrompt::system($user));

        $history = $this->store->history($conversation, $allowance);               // windowed, compacted, whole exchanges

        $eligible = $this->cacheable($question, $history);

        $hit = $eligible
            ? $this->cache->find($question, $user->tenant_id)
            : null;

        if ($hit !== null) {
            return $this->serveFromCache($conversation, $question, $hit, $reply);
        }

        if ($reply !== null && ! $this->health->canAnswer('support.reply')) {
            return $this->unavailable($conversation, $question, $reply, recorded: false);   // Chapter 25: the cache had no answer, the server is down and nothing is named to take over
        }

        $route = $this->route($user, $question, $history);                         // Chapter 17: the first question is classified; a follow-up keeps every tool

        $chunks = $contextChunks !== []
            ? $contextChunks
            : $this->retrieve($user, $question, $route->area);                     // [] is a valid result: the docs do not cover it

        if ($route->isDecline() && $chunks === []) {
            return $this->decline($conversation, $question, $reply);                       // not a Ledgerly question and nothing in the help center: no model call
        }

        $prompt = SupportPrompt::assemble(
            question: $question,
            contextChunks: $chunks,
            history: $history,
            user: $eligible ? null : $user,                                        // memories go into system(), but never into an answer that may be cached for others
            summary: $conversation->summary,
        );

        $this->recordQuestion($conversation, $question, $reply);

        $options = [
            'profile' => 'assistant',
            'max_tokens' => config('ai.budget.answer'),
            'prompt_version' => SupportPrompt::VERSION,
            'conversation_id' => $conversation->id,
            'context_breakdown' => $prompt->breakdown,
            'semantic_cache_hit' => $eligible ? false : null,                      // eligible and missed, versus never eligible
        ];

        $ctx = new ToolContext($user, $conversation->id);

        $sent = count($prompt->messages);

        try {
            $result = $this->runner->run(messages: $prompt->messages, ctx: $ctx, options: $options, feature: 'support.reply', tools: $this->tools($route), stream: $stream);
        } catch (ConnectionException|ProviderUnavailable|CircuitOpen $e) {
            if ($reply === null) {
                throw $e;                                                          // a command or a test called us directly: it wants the error. A person waiting on a page gets the notice.
            }

            return $this->unavailable($conversation, $question, $reply, recorded: true);   // the first calls of an outage fail before the breaker opens; they get the same honest answer, not a failed job
        }

        $result = $this->verify($result, $prompt, $sent, $ctx, $options, $conversation);

        $reply = $this->remember($conversation, $result, sent: $sent, reply: $reply);

        if ($result->citations !== null && $result->citations->cited !== [] && ! $result->citations->hasErrors()) {
            $this->store->recordSources($reply, $result->citations->cited);
        }

        if (! $eligible) {
            MemoryBlock::touch($user);
        }

        if ($eligible) {
            $this->storeInCache($question, $result, sent: $sent, chunks: $chunks, tenantId: $user->tenant_id, known: $route->category);
        }

        if ($this->store->needsMaintenance($conversation->fresh(), $allowance)) {
            SummarizeConversation::dispatch($conversation);
        }

        return $result;
    }

    private function route(User $user, string $question, array $history): Route
    {
        if ($history !== [] || ! $this->router->enabled()) {
            return Route::fallback();
        }

        try {
            $route = $this->router->route($question, $user);
        } catch (CircuitOpen) {
            return Route::fallback();                                              // the classifier's server is the one that is down; the main call decides what to do about it
        } catch (Throwable $e) {
            report($e);

            return Route::fallback();
        }

        return $route->isHuman()
            ? Route::fallback($route->reason)                                      // an attended chat has a person one open_ticket away; a split vote just means every tool
            : $route;
    }

    private function tools(Route $route): ?ToolRegistry
    {
        return $route->tools === null
            ? null
            : $this->registry->only($route->tools);
    }

    private function decline(Conversation $conversation, string $question, ?Message $reply): ToolRunResult
    {
        $this->recordQuestion($conversation, $question, $reply);

        $this->finish($conversation, $reply, ['role' => 'assistant', 'content' => SupportPrompt::declineMessage()]);

        $response = new ChatResponse('router-decline', 'router', SupportPrompt::declineMessage(), [], FinishReason::Stop, new Usage(0, 0), []);

        return new ToolRunResult(response: $response, messages: [], rounds: 0);
    }

    // Chapter 25, Topic 7: the model server is down. The reply says so honestly, is stored as failed (so it is never replayed to the model as its own answer and the job still succeeds), and is logged as 'degraded' for ai:stats.
    private function unavailable(Conversation $conversation, string $question, ?Message $reply, bool $recorded): ToolRunResult
    {
        if (! $recorded) {
            $this->recordQuestion($conversation, $question, $reply);
        }

        ModelCalled::dispatch('support.reply', (string) config('ai.default'), 'degraded', ['prompt_tokens' => 0, 'completion_tokens' => 0], 'unavailable', 0, $conversation->id, SupportPrompt::VERSION);

        $this->finish($conversation, $reply, ['role' => 'assistant', 'content' => SupportPrompt::unavailableMessage(), 'model_call_id' => $this->lastModelCallId($conversation)], 'failed');

        $response = new ChatResponse('degraded', 'degraded', SupportPrompt::unavailableMessage(), [], FinishReason::Stop, new Usage(0, 0), []);

        return new ToolRunResult(response: $response, messages: [], rounds: 0);
    }

    private function retrieve(User $user, string $question, ?string $area = null): array
    {
        try {
            $k = (int) config('ai.retrieval.k', 5);

            $chunks = $this->retriever->retrieve($question, RetrievalScope::forUser($user, $area), $k);

            return $chunks === [] && $area !== null
                ? $this->retriever->retrieve($question, RetrievalScope::forUser($user), $k)       // the area was the classifier's guess: widen it once
                : $chunks;
        } catch (Throwable $e) {
            report($e);                                                            // no passages is the honest fallback: the escape hatch answers

            return [];
        }
    }

    private function verify(ToolRunResult $result, AssembledPrompt $prompt, int $sent, ToolContext $ctx, array $options, Conversation $conversation): ToolRunResult
    {
        if ($prompt->labelMap === [] || $result->response === null || $result->pendingApprovals !== []) {
            return $result;
        }

        if ($result->response->toolCalls !== [] || $result->truncated()) {
            return $result;
        }

        $toolsRan = array_slice($result->messages, $sent) !== [];

        $report = $this->citations->check((string) $result->response->content, $prompt->labelMap, requireCitations: ! $toolsRan);   // an answer built from a tool result is about the account, not the passages

        $this->recordCitationErrors($conversation, count($report->errors));

        if (! $report->hasErrors()) {
            return $result->withCitations($report);
        }

        $retry = $this->runner->run(
            messages: [
                ...$result->messages,
                ['role' => 'assistant', 'content' => $result->response->content],
                ['role' => 'user', 'content' => RepairPrompt::citations($report)],
            ],
            ctx: $ctx,
            options: $options + ['repaired' => true],
            feature: 'support.reply',
        );

        if ($retry->response === null || $retry->response->toolCalls !== [] || $retry->pendingApprovals !== []) {
            return $retry->withContent(SupportPrompt::escapeHatch())->withCitations($report);
        }

        $second = $this->citations->check((string) $retry->response->content, $prompt->labelMap, requireCitations: ! $toolsRan);

        $this->recordCitationErrors($conversation, count($second->errors));

        if ($second->hasErrors()) {
            return $retry->withContent(SupportPrompt::escapeHatch())->withCitations($second);   // twice wrong: say so instead of showing a claim we could not check
        }

        return $retry->withCitations($second);
    }

    private function recordCitationErrors(Conversation $conversation, int $errors): void
    {
        $id = $this->lastModelCallId($conversation);

        if ($id === null) {
            return;
        }

        ModelCall::query()->whereKey($id)->update(['citation_errors' => $errors]);
    }

    private function allShared(array $chunks): bool
    {
        foreach ($chunks as $chunk) {
            if (! $chunk->isShared()) {
                return false;
            }
        }

        return true;
    }

    private function cacheable(string $question, array $history): bool
    {
        if (! $this->policy->enabled()) {
            return false;
        }

        if ($history !== []) {
            return false;                                                          // a follow-up leans on what was said before; only a first question stands alone
        }

        return $this->policy->isGeneric($question);
    }

    private function serveFromCache(Conversation $conversation, string $question, CachedAnswer $hit, ?Message $reply): ToolRunResult
    {
        $started = hrtime(true);

        $this->cache->recordHit($hit);

        ModelCalled::dispatch(
            'support.reply',
            config('ai.default'),
            'semantic-cache',
            ['prompt_tokens' => 0, 'completion_tokens' => 0],
            'cache',
            (int) ((hrtime(true) - $started) / 1_000_000),
            $conversation->id,
            SupportPrompt::VERSION,
            semanticCacheHit: true,
        );

        $this->recordQuestion($conversation, $question, $reply);

        $this->finish($conversation, $reply, [
            'role' => 'assistant',
            'content' => $hit->answer,
            'model_call_id' => $this->lastModelCallId($conversation),
        ]);

        return new ToolRunResult(response: null, messages: [], rounds: 0, cacheHit: $hit);
    }

    private function storeInCache(string $question, ToolRunResult $result, int $sent, array $chunks, ?int $tenantId, ?Category $known = null): void
    {
        if ($result->response === null || $result->response->finishReason !== FinishReason::Stop) {
            return;
        }

        if (array_slice($result->messages, $sent) !== [] || $result->pendingApprovals !== []) {
            return;                                                                // a tool ran: the answer is about this account
        }

        if (! $this->allShared($chunks)) {
            return;                                                                // the model read tenant-private or plan-restricted text: its answer is not for everyone
        }

        if (! $this->policy->worthStoring($result->answer(), grounded: $result->citations?->isGrounded() ?? false)) {
            return;                                                                // with require_context on, only a verified answer built from help-center passages is kept
        }

        try {
            $category = $known ?? ($this->classify)($question);                  // the router already voted on it
        } catch (Throwable $e) {
            report($e);                                                            // an unclassified answer is simply not cached

            return;
        }

        $this->cache->store($question, $category, $result->answer(), $tenantId);
    }

    private function recordQuestion(Conversation $conversation, string $question, ?Message $reply): void
    {
        if ($reply !== null) {
            return;                                                                // Chapter 18: the controller stored the question with the placeholder
        }

        $this->store->append($conversation, ['role' => 'user', 'content' => $question]);
    }

    private function finish(Conversation $conversation, ?Message $reply, array $message, string $status = 'completed'): Message
    {
        if ($reply === null) {
            return $this->store->append($conversation, $message);
        }

        return $this->store->complete($reply, $message, $status);                  // the job updates the placeholder, it never creates a second reply
    }

    private function remember(Conversation $conversation, ToolRunResult $result, int $sent, ?Message $reply = null): Message
    {
        $new = array_slice($result->messages, $sent);                              // assistant tool-call turns and their results

        foreach ($new as $message) {
            $this->store->append($conversation, $message);
        }

        foreach ($this->unansweredToolCalls($new) as $id) {
            $this->store->append($conversation, [
                'role' => 'tool',
                'tool_call_id' => $id,
                'content' => json_encode(['ok' => false, 'error' => 'awaiting approval']),
            ]);
        }

        return $this->finish($conversation, $reply, [
            'role' => 'assistant',
            'content' => $this->finalText($result),
            'model_call_id' => $this->lastModelCallId($conversation),
        ], $result->pendingApprovals !== [] ? 'paused' : 'completed');
    }

    private function finalText(ToolRunResult $result): string
    {
        if ($result->response !== null && $result->response->toolCalls === []) {
            return (string) $result->response->content;
        }

        return $result->answer();                                                  // paused for approval, or a reply cut off while asking for a tool
    }

    private function unansweredToolCalls(array $new): array
    {
        $requested = [];
        $answered = [];

        foreach ($new as $message) {
            foreach ($message['tool_calls'] ?? [] as $call) {
                $requested[] = $call['id'] ?? '';
            }

            if ($message['role'] === 'tool') {
                $answered[] = $message['tool_call_id'] ?? '';
            }
        }

        return array_values(array_diff($requested, $answered));
    }

    private function lastModelCallId(Conversation $conversation): ?int
    {
        return ModelCall::query()
            ->where('conversation_id', $conversation->id)
            ->where('feature', 'support.reply')
            ->latest('id')
            ->value('id');
    }
}
