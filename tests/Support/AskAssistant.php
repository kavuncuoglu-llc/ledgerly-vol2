<?php

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Support\SupportAssistant;
use App\Models\Conversation;
use App\Models\PendingToolCall;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

// Until Chapter 18 POST /support/ask answered in the request. The pipeline tests that grew on that endpoint (cache, router, retrieval, conversations, tools) are about the
// assistant, not the transport, so they call the assistant directly and read the same JSON the endpoint used to return. The queued endpoint has its own tests (ReplyFlowTest).
function askAssistant(User $user, array $payload): TestResponse
{
    test()->actingAs($user);

    $conversation = isset($payload['conversation_id'])
        ? Conversation::query()->where('user_id', $user->id)->find($payload['conversation_id'])
        : app(ConversationStore::class)->start($user, SupportPrompt::VERSION);

    if ($conversation === null) {
        return TestResponse::fromBaseResponse(response()->json(['message' => 'Not found.'], 404));          // what AskRequest::conversation() answers
    }

    if (! $conversation->isOpen()) {
        return TestResponse::fromBaseResponse(response()->json(['message' => 'That conversation is closed. Start a new one.'], 422));
    }

    $result = app(SupportAssistant::class)->answer($user, $payload['question'], $conversation);

    $body = [
        'answer' => $result->answer(),
        'truncated' => $result->truncated(),
        'conversation_id' => $conversation->id,
        'sources' => $result->citations !== null && ! $result->citations->hasErrors()
            ? collect($result->citations->cited)->map(fn ($chunk, string $label) => ['label' => $label, 'url' => $chunk->url()])->values()->all()
            : [],
        'citations_ok' => $result->citations === null || ! $result->citations->hasErrors(),
    ];

    if ($result->cacheHit !== null) {
        $body['semantic_cache'] = ['similarity' => round($result->cacheHit->similarity, 2), 'question' => $result->cacheHit->question];
    }

    if ($result->pendingApprovals !== []) {
        $body['pending_approvals'] = array_map(fn (PendingToolCall $pending) => [
            'id' => $pending->id,
            'summary' => $pending->summary,
            'url' => route('support.approvals.show', $pending),
        ], $result->pendingApprovals);
    }

    return TestResponse::fromBaseResponse(response()->json($body));
}

function streamBody(array $deltas, string $finish = 'stop', array $usage = ['prompt_tokens' => 190, 'completion_tokens' => 24], string $model = 'llama3.1:8b', bool $done = true): string
{
    $chunks = array_map(fn (string $delta) => ['id' => 'chatcmpl-s', 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['content' => $delta], 'finish_reason' => null]]], $deltas);

    $chunks[] = ['id' => 'chatcmpl-s', 'model' => $model, 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $finish]]];
    $chunks[] = ['id' => 'chatcmpl-s', 'model' => $model, 'choices' => [], 'usage' => $usage];

    $body = collect($chunks)->map(fn (array $chunk) => 'data: '.json_encode($chunk)."\n\n")->implode('');

    return $done
        ? $body."data: [DONE]\n\n"
        : $body;
}

function streamReply(array $deltas, string $finish = 'stop', array $usage = ['prompt_tokens' => 190, 'completion_tokens' => 24], bool $done = true): Closure
{
    return fn () => Http::response(streamBody($deltas, $finish, $usage, done: $done), 200, ['Content-Type' => 'text/event-stream']);   // a fresh response per request: a stream can be read once
}

function streamToolCallBody(array $calls, array $usage = ['prompt_tokens' => 310, 'completion_tokens' => 31]): string
{
    $chunks = [];

    foreach (array_values($calls) as $index => [$id, $name, $arguments]) {
        $chunks[] = ['id' => 'chatcmpl-t', 'model' => 'llama3.1:8b', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => $index, 'id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => '']]]], 'finish_reason' => null]]];
        $chunks[] = ['id' => 'chatcmpl-t', 'model' => 'llama3.1:8b', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => $index, 'function' => ['arguments' => json_encode($arguments)]]]], 'finish_reason' => null]]];
    }

    $chunks[] = ['id' => 'chatcmpl-t', 'model' => 'llama3.1:8b', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]];
    $chunks[] = ['id' => 'chatcmpl-t', 'model' => 'llama3.1:8b', 'choices' => [], 'usage' => $usage];

    return collect($chunks)->map(fn (array $chunk) => 'data: '.json_encode($chunk)."\n\n")->implode('')."data: [DONE]\n\n";
}

function streamResponses(string ...$bodies): Closure
{
    $remaining = $bodies;

    return function () use (&$remaining) {
        $next = array_shift($remaining) ?? end($bodies);

        return Http::response($next, 200, ['Content-Type' => 'text/event-stream']);
    };
}
