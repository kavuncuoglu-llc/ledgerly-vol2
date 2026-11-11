<?php

namespace App\Ai\Tracing;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Jobs\AnswerQuestion;
use App\Models\Message;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

// Chapter 31, Build: one question through the whole assistant (the job's code path, retrieval, the sidecar, the model, the tools, the citation check) under one root span, so ai:sample can print the trace id.
final class TracedQuestion
{
    public function __construct(private ConversationStore $store, private CurrentTenant $tenants) {}

    /** @return array{reply: Message, trace_id: ?string} */
    public function ask(User $user, string $question): array
    {
        $root = Tracing::tracer()->spanBuilder('ai:sample')->setSpanKind(SpanKind::KIND_INTERNAL)->startSpan();

        $scope = $root->activate();

        $root->setAttribute('ledgerly.feature', 'support.reply');

        $traceId = $root->getContext()->isValid()
            ? $root->getContext()->getTraceId()
            : null;

        try {
            $reply = $this->tenants->run($user->tenant_id, function () use ($user, $question) {
                $conversation = $this->store->start($user, SupportPrompt::VERSION);

                $this->store->append($conversation, ['role' => 'user', 'content' => $question]);

                $reply = $this->store->placeholder($conversation, $question);

                AnswerQuestion::dispatchSync($reply->id);

                return $reply->fresh();
            });
        } finally {
            $scope->detach();
            $root->end();
        }

        $this->flush();

        return ['reply' => $reply, 'trace_id' => $traceId];
    }

    // A batching exporter sends on a timer or at shutdown; a command that prints a link wants the spans at the collector before it prints.
    private function flush(): void
    {
        $provider = Globals::tracerProvider();

        if ($provider instanceof TracerProviderInterface) {
            $provider->forceFlush();
        }
    }
}
