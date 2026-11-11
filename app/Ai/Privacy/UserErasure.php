<?php

namespace App\Ai\Privacy;

use App\Ai\Training\DatasetFootprint;
use App\Evals\GoldenSet;
use App\Evals\ReviewQueue;
use App\Facades\Ai;
use App\Models\AgentRun;
use App\Models\AnswerFeedback;
use App\Models\AnswerSignal;
use App\Models\CachedAnswer;
use App\Models\Conversation;
use App\Models\HelpChunk;
use App\Models\Message;
use App\Models\MessageSource;
use App\Models\ModelCall;
use App\Models\OnlineJudgment;
use App\Models\PendingToolCall;
use App\Models\ShadowAnswerRecord;
use App\Models\ToolCall;
use App\Models\UserMemory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Chapter 34, Topic 8: every copy in Figure 34-1 that can be deleted on request, and a report of the ones that cannot. The command around it asks, prints and writes the receipt; the rules for what a copy is live here.
// model_calls are anonymized, not deleted: the row stays for cost history, the link to the conversation and the recorded request go (Chapter 13 chose this; Figure 34-8 says "keep aggregate counts for cost history").
final class UserErasure
{
    public function __construct(
        private VaultFactory $vaults,
        private GoldenSet $golden,
        private ReviewQueue $reviewQueue,
    ) {}

    /** @return array{deleted: array<string, int>, anonymized: array<string, int>} what an erasure would touch */
    public function count(ErasureSubject $subject): array
    {
        $scope = $this->scope($subject);

        return $this->report($scope, $this->goldenCasesFor($scope));
    }

    /** @return array{deleted: array<string, int>, anonymized: array<string, int>} */
    public function erase(ErasureSubject $subject): array
    {
        $scope = $this->scope($subject);

        $report = $this->report($scope, $this->goldenCasesFor($scope));                    // counted before anything is changed

        DB::transaction(fn () => $this->deleteRows($scope));

        $this->golden->forgetProduction($scope['originCalls'], $scope['caseIds']);

        $this->reviewQueue->forget($scope['messageIds'], $scope['modelCallIds']);

        foreach ($scope['conversationIds'] as $conversationId) {
            $this->vaults->forget($conversationId);
        }

        return $report;
    }

    /** @return array<string, int|string|null> what cannot be deleted on request, and when each goes by itself */
    public function expiring(): array
    {
        return [
            'logs' => config('ai.privacy.expiry.logs_days').' days',
            'traces' => config('ai.privacy.expiry.traces_days').' days',
            'backups' => config('ai.privacy.expiry.backups_days').' days',
            'provider_copies' => $this->providerCopies(),
        ];
    }

    private function providerCopies(): string
    {
        $hosted = collect(config('ai.providers'))
            ->filter(fn ($config, string $name) => is_array($config) && ($config['hosted'] ?? false) && Ai::configured($name))
            ->map(function (array $config, string $name) {
                $privacy = $config['privacy'] ?? [];

                return $name.': '.match (true) {
                    (bool) ($privacy['zdr'] ?? false) => 'ZDR',
                    filled($privacy['retention_days'] ?? null) => "{$privacy['retention_days']} days",
                    default => 'retention not recorded',
                };
            });

        return $hosted->isEmpty()
            ? 'none: no hosted provider is set up'
            : 'per terms; '.$hosted->implode(', ');
    }

    /** @return array<string, mixed> every id an erasure needs, read before anything is changed */
    private function scope(ErasureSubject $subject): array
    {
        $userIds = $subject->users->pluck('id')->all();

        $conversationIds = Conversation::withoutGlobalScopes()->whereIn('user_id', $userIds)->pluck('id')->all();

        $messageIds = Message::withoutGlobalScopes()->whereIn('conversation_id', $conversationIds)->pluck('id')->all();

        $modelCallIds = ModelCall::query()->whereIn('conversation_id', $conversationIds)->pluck('id')->all();

        return [
            'subject' => $subject,
            'userIds' => $userIds,
            'conversationIds' => $conversationIds,
            'messageIds' => $messageIds,
            'modelCallIds' => $modelCallIds,
            'originCalls' => array_map(fn (int $id) => "model_calls:{$id}", $modelCallIds),
            'caseIds' => array_map(fn (int $id) => "production-{$id}", $messageIds),
            'questions' => Message::withoutGlobalScopes()->whereIn('id', $messageIds)->where('role', 'user')->pluck('content')->filter()->unique()->values()->all(),
        ];
    }

    private function goldenCasesFor(array $scope): int
    {
        $cases = $this->golden->all('production')
            ->filter(fn ($case) => in_array($case->originCall, $scope['originCalls'], true) || in_array($case->id, $scope['caseIds'], true))
            ->count();

        $queued = $this->reviewQueue->all()
            ->filter(fn (array $row) => in_array($row['message_id'] ?? null, $scope['messageIds'], true) || in_array($row['model_call_id'] ?? null, $scope['modelCallIds'], true))
            ->count();

        return $cases + $queued;
    }

    private function deleteRows(array $scope): void
    {
        ModelCall::query()->whereIn('id', $scope['modelCallIds'])->update(['conversation_id' => null, 'request' => null]);

        OnlineJudgment::query()->whereIn('message_id', $scope['messageIds'])->delete();

        Message::withoutGlobalScopes()->whereIn('id', $scope['messageIds'])->delete();                     // message_sources, feedback, signals and shadow answers go with their message

        Conversation::withoutGlobalScopes()->whereIn('id', $scope['conversationIds'])->delete();

        UserMemory::withoutGlobalScopes()->whereIn('user_id', $scope['userIds'])->delete();

        ToolCall::query()->whereIn('user_id', $scope['userIds'])->delete();

        PendingToolCall::query()->whereIn('user_id', $scope['userIds'])->delete();

        AgentRun::query()->whereIn('user_id', $scope['userIds'])->delete();

        $this->cachedAnswers($scope)->delete();

        $this->privatePassages($scope)?->delete();
    }

    private function report(array $scope, int $goldenCases): array
    {
        return [
            'deleted' => [
                'conversations' => count($scope['conversationIds']),
                'messages' => count($scope['messageIds']),
                'memories' => UserMemory::withoutGlobalScopes()->whereIn('user_id', $scope['userIds'])->count(),
                'tool_calls' => ToolCall::query()->whereIn('user_id', $scope['userIds'])->count(),
                'message_sources' => MessageSource::withoutGlobalScopes()->whereIn('message_id', $scope['messageIds'])->count(),
                'feedback' => AnswerFeedback::withoutGlobalScopes()->whereIn('message_id', $scope['messageIds'])->count(),
                'signals' => AnswerSignal::query()->whereIn('message_id', $scope['messageIds'])->count(),
                'shadow_answers' => ShadowAnswerRecord::query()->whereIn('message_id', $scope['messageIds'])->count(),
                'judgments' => OnlineJudgment::query()->whereIn('message_id', $scope['messageIds'])->count(),
                'agent_runs' => AgentRun::query()->whereIn('user_id', $scope['userIds'])->count(),
                'semantic_cache_entries' => $this->cachedAnswers($scope)->count(),
                'private_passages' => $this->privatePassages($scope)?->count() ?? 0,
                'golden_cases' => $goldenCases,
                'vaults' => $this->vaultCount($scope['conversationIds']),
                'pending_actions' => PendingToolCall::query()->whereIn('user_id', $scope['userIds'])->count(),
            ],
            'anonymized' => [
                'model_calls' => count($scope['modelCallIds']),
            ],
            'datasets' => (new DatasetFootprint)->of($scope['conversationIds']),         // Chapter 40: copies in training datasets are found, not deleted: dataset:forget builds the next version without them
        ];
    }

    // A person's questions that the cache stored under their tenant; for a whole customer, everything stored under the customer's tenant. Entries every tenant shares hold no customer text.
    private function cachedAnswers(array $scope): Builder
    {
        $subject = $scope['subject'];

        $query = CachedAnswer::query()->whereNotNull('tenant_id');

        if ($subject->tenantId !== null) {
            return $query->where('tenant_id', $subject->tenantId);
        }

        return $query->whereIn('question', $scope['questions'] ?: ['']);
    }

    // A customer account's own help-center passages (Volume 3's uploads, today a tenant's private articles) are its data too.
    private function privatePassages(array $scope): ?Builder
    {
        $tenantId = $scope['subject']->tenantId;

        return $tenantId === null
            ? null
            : HelpChunk::query()->where('tenant_id', $tenantId);
    }

    private function vaultCount(array $conversationIds): int
    {
        return Collection::make($conversationIds)
            ->filter(fn (int $id) => ! $this->vaults->for($id)->isEmpty())
            ->count();
    }
}
