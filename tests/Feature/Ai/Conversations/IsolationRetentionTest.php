<?php

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\PendingToolCall;
use App\Models\Tenant;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserMemory;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

it('never leaks history across tenants', function () {
    [$a, $b] = Tenant::factory(2)->create();
    $convA = conversationWithMessages($a, ['secret-alpha-4471']);
    $convB = conversationWithMessages($b, ['secret-beta-9920']);

    actingAsTenant($b);
    $history = app(ConversationStore::class)->history($convB->fresh(), budgetTokens: 4000);
    expect(json_encode($history))->not->toContain('alpha');
    expect(fn () => Conversation::findOrFail($convA->id))->toThrow(ModelNotFoundException::class);
});

it('scopes messages as well as conversations', function () {
    [$a, $b] = Tenant::factory(2)->create();
    conversationWithMessages($a, ['secret-alpha-4471']);
    conversationWithMessages($b, ['secret-beta-9920']);

    actingAsTenant($b);

    expect(Message::pluck('content')->join(' '))->not->toContain('alpha')
        ->and(Message::count())->toBe(2)
        ->and(Conversation::count())->toBe(1);
});

it('scopes relationship loads too', function () {
    [$a, $b] = Tenant::factory(2)->create();
    $convA = conversationWithMessages($a, ['secret-alpha-4471']);
    conversationWithMessages($b, ['secret-beta-9920']);

    actingAsTenant($b);

    expect($convA->messages()->count())->toBe(0);
});

it('scopes by the signed-in user\'s tenant without any setup', function () {
    [$a, $b] = Tenant::factory(2)->create();
    conversationWithMessages($a, ['secret-alpha-4471']);
    $mine = conversationWithMessages($b, ['secret-beta-9920']);

    $this->actingAs($mine->user);

    expect(Conversation::pluck('id')->all())->toBe([$mine->id]);
});

it('shows nothing to a signed-in user who belongs to no tenant', function () {
    [$a] = Tenant::factory(1)->create();
    conversationWithMessages($a, ['secret-alpha-4471']);

    $this->actingAs(User::factory()->support()->create());

    expect(Conversation::count())->toBe(0);
});

it('fails closed on a web request with nobody signed in, but not in the console', function () {
    [$a] = Tenant::factory(1)->create();
    conversationWithMessages($a, ['secret-alpha-4471']);

    expect(Conversation::count())->toBe(1);                              // console: unscoped, which is how jobs and commands work

    $property = new ReflectionProperty($this->app, 'isRunningInConsole');
    $property->setValue($this->app, false);                              // pretend this is a web request

    expect(Conversation::count())->toBe(0)
        ->and(app(CurrentTenant::class)->run($a->id, fn () => Conversation::count()))->toBe(1);

    $property->setValue($this->app, true);
});

it('lets a job run with an explicit tenant, and restores the previous one', function () {
    [$a, $b] = Tenant::factory(2)->create();
    conversationWithMessages($a, ['alpha']);
    conversationWithMessages($b, ['beta']);

    $tenant = app(CurrentTenant::class);

    $tenant->set($a->id);

    expect($tenant->run($b->id, fn () => Conversation::count()))->toBe(1)
        ->and($tenant->run(null, fn () => Conversation::count()))->toBe(2)
        ->and(Conversation::first()->tenant_id)->toBe($a->id);
});

it('stamps new rows with the current tenant', function () {
    [$a] = Tenant::factory(1)->create();

    actingAsTenant($a);

    $conversation = Conversation::create(['user_id' => 1, 'status' => 'open', 'prompt_version' => SupportPrompt::VERSION]);

    expect($conversation->tenant_id)->toBe($a->id);
});

describe('conversations:retain', function () {
    it('archives closed conversations past the retention period and scrubs their messages', function () {
        $user = User::factory()->create();

        $old = conversationFor($user, status: 'closed');
        addExchange($old, 'old question', 'old answer');
        $old->update(['summary' => 'old summary']);
        Conversation::withoutGlobalScopes()->whereKey($old->id)->toBase()->update(['updated_at' => now()->subDays(91)]);

        $recent = conversationFor($user, status: 'closed');
        addExchange($recent, 'recent question', 'recent answer');
        Conversation::withoutGlobalScopes()->whereKey($recent->id)->toBase()->update(['updated_at' => now()->subDays(10)]);

        $open = conversationFor($user);
        addExchange($open, 'open question', 'open answer');
        Conversation::withoutGlobalScopes()->whereKey($open->id)->toBase()->update(['updated_at' => now()->subDays(400)]);

        $this->artisan('conversations:retain')->expectsOutputToContain('Archived 1 closed conversations')->assertSuccessful();

        expect($old->fresh()->status)->toBe('archived')
            ->and($old->fresh()->summary)->toBeNull()
            ->and(Message::where('conversation_id', $old->id)->count())->toBe(0)
            ->and($recent->fresh()->status)->toBe('closed')
            ->and(Message::where('conversation_id', $recent->id)->count())->toBe(2)
            ->and($open->fresh()->status)->toBe('open')
            ->and(Message::where('conversation_id', $open->id)->count())->toBe(2);
    });

    it('reads the retention period from config', function () {
        config(['ai.retention.closed_conversation_days' => 5]);

        $conversation = conversationFor(User::factory()->create(), status: 'closed');
        addExchange($conversation, 'q', 'a');
        Conversation::withoutGlobalScopes()->whereKey($conversation->id)->toBase()->update(['updated_at' => now()->subDays(6)]);

        $this->artisan('conversations:retain')->assertSuccessful();

        expect($conversation->fresh()->status)->toBe('archived');
    });

    it('works across every tenant, whoever is signed in', function () {
        [$a, $b] = Tenant::factory(2)->create();

        foreach ([$a, $b] as $tenant) {
            $conversation = conversationWithMessages($tenant, ['hello']);
            Conversation::withoutGlobalScopes()->whereKey($conversation->id)->toBase()->update(['status' => 'closed', 'updated_at' => now()->subDays(100)]);
        }

        actingAsTenant($a);

        $this->artisan('conversations:retain')->expectsOutputToContain('Archived 2 closed conversations')->assertSuccessful();
    });

    it('sweeps memories nobody has used for a year', function () {
        $user = User::factory()->create();

        $stale = UserMemory::create(['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'key' => 'stale', 'value' => 'x', 'source' => 'stated', 'last_used_at' => now()->subDays(400)]);
        $neverUsed = UserMemory::create(['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'key' => 'never', 'value' => 'x', 'source' => 'stated']);
        UserMemory::withoutGlobalScopes()->whereKey($neverUsed->id)->toBase()->update(['created_at' => now()->subDays(400)]);
        $fresh = UserMemory::create(['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'key' => 'fresh', 'value' => 'x', 'source' => 'stated', 'last_used_at' => now()->subDays(3)]);
        $new = UserMemory::create(['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'key' => 'new', 'value' => 'x', 'source' => 'stated']);

        $this->artisan('conversations:retain')->expectsOutputToContain('removed 2 unused memories')->assertSuccessful();

        expect(UserMemory::orderBy('key')->pluck('key')->all())->toBe(['fresh', 'new']);
    });

    it('is on the scheduler, daily', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'conversations:retain'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('0 0 * * *');
    });
});

describe('user:forget', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->other = User::factory()->inTenant($this->user->tenant)->create();

        foreach ([$this->user, $this->other] as $person) {
            $conversation = conversationFor($person);
            addExchange($conversation, "{$person->id} says hello", 'hi');
            addExchange($conversation, 'second', 'ok');

            UserMemory::create(['user_id' => $person->id, 'tenant_id' => $person->tenant_id, 'key' => 'role', 'value' => 'x', 'source' => 'stated']);
            ToolCall::create(['user_id' => $person->id, 'conversation_id' => $conversation->id, 'tool' => 'lookup_invoice', 'arguments' => ['invoice_number' => '4471'], 'decision' => 'allowed', 'result' => ['ok' => true]]);
            ModelCall::create(['feature' => 'support.reply', 'provider' => 'ollama', 'model' => 'm', 'prompt_tokens' => 10, 'completion_tokens' => 5, 'finish_reason' => 'stop', 'latency_ms' => 1, 'conversation_id' => $conversation->id]);
        }

        PendingToolCall::create(['user_id' => $this->user->id, 'tool' => 'issue_refund', 'tool_call_id' => 'c', 'arguments' => [], 'summary' => 'Refund', 'messages' => [['role' => 'user', 'content' => 'secret']]]);
    });

    it('counts and deletes nothing without --confirm', function () {
        $this->artisan('user:forget', ['user' => $this->user->id])
            ->expectsOutputToContain('would delete  conversations 1 · messages 4 · memories 1 · tool_calls 1')
            ->assertFailed();

        expect(Conversation::count())->toBe(2);
    });

    it('deletes the user\'s conversations, messages, memories and tool-call content, and keeps everyone else\'s', function () {
        $this->artisan('user:forget', ['user' => $this->user->id, '--confirm' => true])
            ->expectsOutputToContain('deleted  conversations 1 · messages 4 · memories 1 · tool_calls 1')
            ->expectsOutputToContain('anonymized  model_calls 1')
            ->assertSuccessful();

        expect(Conversation::where('user_id', $this->user->id)->count())->toBe(0)
            ->and(Message::count())->toBe(4)
            ->and(UserMemory::pluck('user_id')->all())->toBe([$this->other->id])
            ->and(ToolCall::pluck('user_id')->all())->toBe([$this->other->id])
            ->and(PendingToolCall::count())->toBe(0)
            ->and(Conversation::where('user_id', $this->other->id)->count())->toBe(1);
    });

    it('keeps the model_calls rows for cost reporting but cuts the link to the person', function () {
        $this->artisan('user:forget', ['user' => $this->user->id, '--confirm' => true])->assertSuccessful();

        expect(ModelCall::count())->toBe(2)
            ->and(ModelCall::whereNull('conversation_id')->count())->toBe(1)
            ->and(ModelCall::sum('prompt_tokens'))->toBe(20);
    });

    it('logs counts to the audit trail and never the content', function () {
        Log::spy();

        $this->artisan('user:forget', ['user' => $this->user->id, '--confirm' => true])->assertSuccessful();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'user.forgotten'
            && $context['user_id'] === $this->user->id
            && $context['messages'] === 4
            && ! str_contains(json_encode($context), 'hello'))->once();
    });

    it('says so for a user that does not exist', function () {
        $this->artisan('user:forget', ['user' => 99999, '--confirm' => true])->expectsOutputToContain('There is no user 99999.')->assertFailed();
    });

    it('works whatever tenant is current', function () {
        actingAsTenant(Tenant::factory()->create());

        $this->artisan('user:forget', ['user' => $this->user->id, '--confirm' => true])->assertSuccessful();

        expect(Conversation::withoutGlobalScopes()->where('user_id', $this->user->id)->count())->toBe(0);
    });
});
