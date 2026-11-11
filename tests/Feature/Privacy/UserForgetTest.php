<?php

use App\Ai\Privacy\Span;
use App\Ai\Privacy\VaultFactory;
use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use App\Evals\ReviewQueue;
use App\Models\AgentRun;
use App\Models\AnswerFeedback;
use App\Models\CachedAnswer;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\ErasureRequest;
use App\Models\HelpChunk;
use App\Models\Message;
use App\Models\MessageSource;
use App\Models\ModelCall;
use App\Models\PendingToolCall;
use App\Models\Tenant;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// Chapter 34, Topic 8 and Figure 34-9: user:forget reaches every copy on the data map that can be deleted on request, says what expires on its own, and leaves a receipt.
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/ledgerly-forget-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($this->dir.'/production');

    app()->instance(GoldenSet::class, new GoldenSet($this->dir));
    app()->instance(ReviewQueue::class, new ReviewQueue($this->dir.'-review-queue.jsonl'));

    $this->tenant = Tenant::factory()->create();

    $this->user = User::factory()->inTenant($this->tenant)->create();
    $this->colleague = User::factory()->inTenant($this->tenant)->create();
    $this->stranger = User::factory()->inTenant(Tenant::factory()->create())->create();

    $this->copies = [];

    foreach ([$this->user, $this->colleague, $this->stranger] as $person) {
        $conversation = conversationFor($person);

        addExchange($conversation, "Question from {$person->id}", 'An answer.');

        $call = ModelCall::create(['feature' => 'support.reply', 'provider' => 'local', 'model' => 'm', 'prompt_tokens' => 10, 'completion_tokens' => 5, 'finish_reason' => 'stop', 'latency_ms' => 1, 'conversation_id' => $conversation->id, 'request' => ['messages' => [['role' => 'user', 'content' => "Question from {$person->id}"]]]]);

        $reply = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('role', 'assistant')->first();

        MessageSource::withoutGlobalScopes()->create(['message_id' => $reply->id, 'tenant_id' => $person->tenant_id, 'label' => 'a', 'source' => 's', 'heading_path' => 'h', 'url' => '/u']);
        AnswerFeedback::withoutGlobalScopes()->create(['message_id' => $reply->id, 'tenant_id' => $person->tenant_id, 'rating' => 'down']);

        UserMemory::withoutGlobalScopes()->create(['user_id' => $person->id, 'tenant_id' => $person->tenant_id, 'key' => 'billing_contact', 'value' => 'x@acme.example', 'source' => 'stated']);
        ToolCall::create(['user_id' => $person->id, 'conversation_id' => $conversation->id, 'tool' => 'lookup_invoice', 'arguments' => ['invoice_number' => '4471'], 'decision' => 'allowed', 'result' => ['ok' => true]]);
        PendingToolCall::create(['user_id' => $person->id, 'tool' => 'change_billing_email', 'tool_call_id' => 'c', 'arguments' => ['new_email' => 'x@acme.example'], 'summary' => 'Change', 'messages' => []]);
        AgentRun::create(['state_id' => (string) Str::uuid(), 'agent' => 'support', 'feature' => 'support.reply', 'user_id' => $person->id, 'tenant_id' => $person->tenant_id, 'conversation_id' => $conversation->id, 'goal' => 'Resolve it', 'state' => [], 'status' => 'done']);

        $text = "x{$person->id}@acme.example";

        app(VaultFactory::class)->for($conversation->id)->tokenize($text, [new Span('EMAIL', 0, strlen($text), $text)]);

        $this->copies[$person->id] = ['conversation' => $conversation, 'call' => $call, 'reply' => $reply];
    }

    $cached = fn (User $person, string $question) => CachedAnswer::create(['tenant_id' => $person->tenant_id, 'category' => 'billing', 'question' => $question, 'answer' => 'An answer.', 'help_version' => 'v1', 'status' => 'auto', 'embedding' => angledVector(0)]);

    $this->cacheEntry = $cached($this->user, "Question from {$this->user->id}");
    $this->otherCacheEntry = $cached($this->user, 'A question nobody here asked');
    $this->strangersEntry = $cached($this->stranger, "Question from {$this->stranger->id}");

    $golden = fn (User $person) => new GoldenCase(id: 'production-'.$this->copies[$person->id]['reply']->id, category: 'billing', source: 'production', customer: 'acme', question: 'a redacted question', expectedBehavior: 'answer', originCall: 'model_calls:'.$this->copies[$person->id]['call']->id);

    (new GoldenSet($this->dir))->write($this->dir.'/production/2026-11-03.jsonl', [$golden($this->user), $golden($this->stranger)]);

    File::put($this->dir.'-review-queue.jsonl', collect([
        ['id' => 'q1', 'status' => 'open', 'message_id' => $this->copies[$this->user->id]['reply']->id, 'model_call_id' => $this->copies[$this->user->id]['call']->id, 'question' => 'a question'],
        ['id' => 'q2', 'status' => 'open', 'message_id' => $this->copies[$this->stranger->id]['reply']->id, 'model_call_id' => $this->copies[$this->stranger->id]['call']->id, 'question' => 'a question'],
    ])->map(fn ($row) => json_encode($row))->implode("\n")."\n");
})->group('privacy');

afterEach(function () {
    File::deleteDirectory($this->dir);

    File::delete($this->dir.'-review-queue.jsonl');
});

function vaultHolds(Conversation $conversation): bool
{
    return ! app(VaultFactory::class)->for($conversation->id)->isEmpty();
}

it('counts everything and deletes nothing without --confirm, receipt included', function () {
    $this->artisan('user:forget', ['user' => $this->user->id])
        ->expectsOutputToContain('would delete  conversations 1 · messages 2 · memories 1 · tool_calls 1 · message_sources 1 · agent_runs 1')
        ->expectsOutputToContain('semantic-cache entries 1 · private passages 0 · golden cases 2 (production) · vaults 1 · pending actions 1')
        ->assertFailed();

    expect(Conversation::count())->toBe(3)
        ->and(CachedAnswer::count())->toBe(3)
        ->and(vaultHolds($this->copies[$this->user->id]['conversation']))->toBeTrue()
        ->and(ErasureRequest::count())->toBe(0)
        ->and(app(GoldenSet::class)->all('production'))->toHaveCount(2);
});

it('deletes every copy that can be deleted for one user and keeps everyone else\'s', function () {
    $this->artisan('user:forget', ['user' => $this->user->id, '--confirm' => true, '--reason' => 'erasure request 2026-11-03'])->assertSuccessful();

    $mine = $this->copies[$this->user->id];

    $others = [$this->copies[$this->colleague->id], $this->copies[$this->stranger->id]];

    expect(Conversation::withoutGlobalScopes()->where('user_id', $this->user->id)->count())->toBe(0)
        ->and(Message::withoutGlobalScopes()->count())->toBe(4)
        ->and(MessageSource::withoutGlobalScopes()->count())->toBe(2)
        ->and(AnswerFeedback::withoutGlobalScopes()->count())->toBe(2)
        ->and(UserMemory::withoutGlobalScopes()->pluck('user_id')->sort()->values()->all())->toBe(collect([$this->colleague->id, $this->stranger->id])->sort()->values()->all())
        ->and(ToolCall::count())->toBe(2)
        ->and(PendingToolCall::count())->toBe(2)
        ->and(AgentRun::count())->toBe(2)
        ->and(vaultHolds($mine['conversation']))->toBeFalse()
        ->and(vaultHolds($others[0]['conversation']))->toBeTrue()
        ->and(CachedAnswer::pluck('id')->sort()->values()->all())->toBe(collect([$this->otherCacheEntry->id, $this->strangersEntry->id])->sort()->values()->all())
        ->and(app(GoldenSet::class)->all('production')->pluck('id')->all())->toBe(['production-'.$this->copies[$this->stranger->id]['reply']->id])
        ->and(app(ReviewQueue::class)->all()->pluck('id')->all())->toBe(['q2']);
});

it('anonymizes the model_calls rows instead of deleting them: the counts stay, the person and the recorded request go', function () {
    $this->artisan('user:forget', ['user' => $this->user->id, '--confirm' => true])->assertSuccessful();

    $call = $this->copies[$this->user->id]['call']->fresh();

    expect(ModelCall::count())->toBe(3)
        ->and($call->conversation_id)->toBeNull()
        ->and($call->request)->toBeNull()
        ->and($call->prompt_tokens)->toBe(10);
});

it('writes a receipt that names the subject by id, gives the reason, and holds no content', function () {
    $this->artisan('user:forget', ['user' => $this->user->id, '--confirm' => true, '--reason' => 'erasure request 2026-11-03'])
        ->expectsOutputToContain('receipt     erasure_requests #')
        ->assertSuccessful();

    $receipt = ErasureRequest::sole();

    expect($receipt->subject)->toBe("user:{$this->user->id}")
        ->and($receipt->reason)->toBe('erasure request 2026-11-03')
        ->and($receipt->deleted['conversations'])->toBe(1)
        ->and($receipt->deleted['golden_cases'])->toBe(2)
        ->and($receipt->anonymized)->toBe(['model_calls' => 1])
        ->and(json_encode($receipt->toArray()))->not->toContain('Question from')->not->toContain('@acme.example');
});

it('reports the copies it cannot touch, with when each goes by itself', function () {
    config([
        'ai.privacy.expiry' => ['logs_days' => 14, 'traces_days' => 7, 'backups_days' => 35],
        'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test',
        'ai.providers.frontier.model' => 'frontier-example',
        'ai.providers.frontier.privacy.zdr' => true,
    ]);

    $this->artisan('user:forget', ['user' => $this->user->id, '--confirm' => true])
        ->expectsOutputToContain('expiring    logs (14 days) · traces (7 days) · backups (35 days) · provider copies (per terms; frontier: ZDR)')
        ->assertSuccessful();

    expect(ErasureRequest::sole()->expiring['provider_copies'])->toBe('per terms; frontier: ZDR');
});

it('says there is no provider copy when no hosted provider is set up', function () {
    $this->artisan('user:forget', ['user' => $this->user->id, '--confirm' => true])
        ->expectsOutputToContain('provider copies (none: no hosted provider is set up)')
        ->assertSuccessful();
});

it('erases a whole customer account: every user, the tenant\'s cache and its private passages', function () {
    $customerId = Customer::factory()->create()->id;

    User::query()->whereIn('id', [$this->user->id, $this->colleague->id])->update(['customer_id' => $customerId]);

    DB::table('tenants')->where('id', $this->tenant->id)->update(['customer_id' => $customerId]);

    HelpChunk::create(['source' => 'help/private/acme.md', 'position' => 1, 'heading' => 'Acme', 'content' => 'Private to Acme.', 'tokens' => 4, 'title' => 'Acme', 'heading_path' => 'Acme', 'tags' => [], 'help_version' => 'v1', 'chunk_key' => 'k1', 'tenant_id' => $this->tenant->id, 'embedding' => angledVector(0)]);

    $this->artisan('user:forget', ['user' => "customer:{$customerId}", '--confirm' => true, '--reason' => 'account closed'])->assertSuccessful();

    expect(Conversation::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(Conversation::withoutGlobalScopes()->count())->toBe(1)
        ->and(CachedAnswer::where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(CachedAnswer::where('tenant_id', $this->stranger->tenant_id)->count())->toBe(1)
        ->and(HelpChunk::where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(ErasureRequest::sole()->subject)->toBe("customer:{$customerId}");
});

it('says so for a customer that does not exist', function () {
    $this->artisan('user:forget', ['user' => 'customer:99999', '--confirm' => true])->expectsOutputToContain('There is no user customer:99999.')->assertFailed();

    expect(ErasureRequest::count())->toBe(0);
});
