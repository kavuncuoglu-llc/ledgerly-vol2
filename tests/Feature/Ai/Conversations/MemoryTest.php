<?php

use App\Ai\Conversations\ConversationStore;
use App\Ai\Memory\ExtractMemories;
use App\Ai\Memory\MemoryBlock;
use App\Ai\Prompts\ExtractMemoriesPrompt;
use App\Ai\Prompts\SupportPrompt;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    $this->user = User::factory()->create();
});

function remember(User $user, string $key, string $value, string $source = 'stated'): UserMemory
{
    return UserMemory::create(['user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'key' => $key, 'value' => $value, 'source' => $source]);
}

function finishedConversation(User $user, array $said = ["I'm the bookkeeper for Acme Studios, not the owner.", 'Please send receipts to billing@acme.example.']): Conversation
{
    $conversation = conversationFor($user, status: 'closed');

    foreach ($said as $line) {
        addExchange($conversation, $line, 'Noted.');
    }

    return $conversation;
}

describe('MemoryBlock', function () {
    it('renders nothing when there is nothing to remember', function () {
        expect(MemoryBlock::render($this->user))->toBe('');
    });

    it('renders the Figure 13-6 block, keys in order, one line per fact', function () {
        remember($this->user, 'timezone', 'America/New_York');
        remember($this->user, 'role', 'bookkeeper for Acme Studios, not the owner');
        remember($this->user, 'billing_contact', 'billing@acme.example');

        expect(MemoryBlock::render($this->user))->toBe(<<<'TEXT'
        What you know about this customer (they can edit this in Settings):
        - billing_contact: billing@acme.example
        - role: bookkeeper for Acme Studios, not the owner
        - timezone: America/New_York
        TEXT);
    });

    it('renders the same bytes every time, so the system message stays a cacheable prefix', function () {
        remember($this->user, 'role', "bookkeeper\n  for   Acme");
        remember($this->user, 'timezone', 'UTC');

        expect(MemoryBlock::render($this->user))->toBe(MemoryBlock::render($this->user))
            ->and(MemoryBlock::render($this->user))->toContain('- role: bookkeeper for Acme');
    });

    it('caps the block at a dozen facts', function () {
        foreach (range(1, 20) as $n) {
            remember($this->user, sprintf('fact_%02d', $n), "value {$n}");
        }

        expect(substr_count(MemoryBlock::render($this->user), "\n- "))->toBe(12);
    });

    it('only reads the user\'s own facts', function () {
        remember(User::factory()->create(), 'role', 'someone else');

        expect(MemoryBlock::render($this->user))->toBe('');
    });

    it('rides inside SupportPrompt::system(), after the unchanged base text', function () {
        remember($this->user, 'role', 'bookkeeper');

        expect(SupportPrompt::system($this->user))->toStartWith(SupportPrompt::system()."\n\nWhat you know about this customer")
            ->and(SupportPrompt::system())->not->toContain('What you know about this customer');
    });
});

describe('ExtractMemories', function () {
    function extractionReply(array $facts): void
    {
        Http::fake(['*/chat/completions' => fn () => chatReply(json_encode($facts))]);
    }

    it('stores the stated facts as extracted, keyed, with their source conversation', function () {
        $conversation = finishedConversation($this->user);

        extractionReply([
            ['key' => 'role', 'value' => 'bookkeeper for Acme Studios, not the owner', 'confidence' => 0.95],
            ['key' => 'billing_contact', 'value' => 'billing@acme.example', 'confidence' => 0.9],
        ]);

        ExtractMemories::dispatchSync($conversation);

        $memories = UserMemory::orderBy('key')->get();

        expect($memories->pluck('key')->all())->toBe(['billing_contact', 'role'])
            ->and($memories->pluck('source')->unique()->all())->toBe(['extracted'])
            ->and($memories->pluck('conversation_id')->unique()->all())->toBe([$conversation->id])
            ->and($memories->pluck('user_id')->unique()->all())->toBe([$this->user->id])
            ->and($memories->pluck('tenant_id')->unique()->all())->toBe([$this->user->tenant_id]);
    });

    it('asks with the extraction prompt, the deterministic profile and only the words said', function () {
        $conversation = finishedConversation($this->user);

        extractionReply([]);

        ExtractMemories::dispatchSync($conversation);

        $body = sentChatBodies()[0];

        expect($body['temperature'])->toBe(0)
            ->and($body['messages'][0]['content'])->toBe(ExtractMemoriesPrompt::system())
            ->and($body['messages'][1]['content'])->toContain("Customer: I'm the bookkeeper for Acme Studios, not the owner.")
            ->and($body['messages'][1]['content'])->toContain('Assistant: Noted.');
    });

    it('discards low-confidence facts', function () {
        extractionReply([
            ['key' => 'role', 'value' => 'bookkeeper for Acme Studios', 'confidence' => 0.4],
            ['key' => 'billing_contact', 'value' => 'billing@acme.example', 'confidence' => 0.7],
        ]);

        ExtractMemories::dispatchSync(finishedConversation($this->user));

        expect(UserMemory::pluck('key')->all())->toBe(['billing_contact']);
    });

    it('upserts by key: the newer value wins and nothing is duplicated', function () {
        remember($this->user, 'role', 'owner of Acme Studios', source: 'extracted');

        extractionReply([['key' => 'role', 'value' => 'bookkeeper for Acme Studios, not the owner', 'confidence' => 0.95]]);

        ExtractMemories::dispatchSync(finishedConversation($this->user));

        expect(UserMemory::count())->toBe(1)
            ->and(UserMemory::first()->value)->toBe('bookkeeper for Acme Studios, not the owner');
    });

    it('never overwrites a fact the customer entered themselves', function () {
        remember($this->user, 'role', 'accountant', source: 'stated');

        extractionReply([['key' => 'role', 'value' => 'bookkeeper for Acme Studios, not the owner', 'confidence' => 0.95]]);

        ExtractMemories::dispatchSync(finishedConversation($this->user));

        expect(UserMemory::sole()->value)->toBe('accountant');
    });

    it('never stores a fact the customer did not state (Exercise 4)', function () {
        $conversation = conversationFor($this->user, status: 'closed');

        addExchange($conversation, 'Why is INV-4471 late?', 'It looks like you are frustrated with late fees, and probably a small business owner who struggles with cash flow.');

        extractionReply([
            ['key' => 'mood', 'value' => 'frustrated about late fees', 'confidence' => 0.95],
            ['key' => 'business_health', 'value' => 'struggles with cash flow', 'confidence' => 0.9],
        ]);

        ExtractMemories::dispatchSync($conversation);

        expect(UserMemory::count())->toBe(0);
    });

    it('accepts the JSON a model wraps in a code fence, and ignores anything else', function () {
        Http::fake(['*/chat/completions' => fn () => chatReply("```json\n[{\"key\":\"Preferred Name\",\"value\":\"Maya\",\"confidence\":0.9}]\n```")]);

        ExtractMemories::dispatchSync(finishedConversation($this->user, ['Please call me Maya.']));

        expect(UserMemory::sole()->key)->toBe('preferred_name');

        Http::fake(['*/chat/completions' => fn () => chatReply('I could not find any facts, sorry!')]);

        ExtractMemories::dispatchSync(finishedConversation($this->user));

        expect(UserMemory::count())->toBe(1);
    });

    it('stores nothing when the model fails', function () {
        Http::fake(['*/chat/completions' => Http::response('boom', 500)]);

        ExtractMemories::dispatchSync(finishedConversation($this->user));

        expect(UserMemory::count())->toBe(0);
    });

    it('does not call the model for a conversation where the customer said nothing', function () {
        Http::fake();

        ExtractMemories::dispatchSync(conversationFor($this->user, status: 'closed'));

        Http::assertNothingSent();
    });

    it('runs when a conversation closes, and only then', function () {
        Bus::fake([ExtractMemories::class]);

        $conversation = conversationFor($this->user);

        app(ConversationStore::class)->close($conversation);

        Bus::assertDispatched(ExtractMemories::class, fn (ExtractMemories $job) => $job->conversation->is($conversation));
        expect($conversation->fresh()->status)->toBe('closed');

        app(ConversationStore::class)->close($conversation->fresh());

        Bus::assertDispatchedTimes(ExtractMemories::class, 1);
    });

    it('closes through the endpoint, for the owner of the conversation only', function () {
        Bus::fake([ExtractMemories::class]);

        $conversation = conversationFor($this->user);

        $this->actingAs(User::factory()->inTenant($this->user->tenant)->create())->postJson(route('support.conversations.close', $conversation))->assertForbidden();

        $this->actingAs($this->user)->postJson(route('support.conversations.close', $conversation))->assertOk()->assertJson(['status' => 'closed']);

        Bus::assertDispatched(ExtractMemories::class);
    });
});

describe('the settings page', function () {
    it('lists the user\'s facts and nobody else\'s', function () {
        remember($this->user, 'role', 'bookkeeper');
        remember(User::factory()->create(), 'role', 'secret-beta-9920');

        $this->actingAs($this->user)->get(route('settings.memories.index'))
            ->assertOk()
            ->assertSee('bookkeeper')
            ->assertDontSee('secret-beta-9920');
    });

    it('lets the user delete a fact, and only their own', function () {
        $mine = remember($this->user, 'role', 'bookkeeper');
        $theirs = remember(User::factory()->inTenant($this->user->tenant)->create(), 'role', 'someone else');

        $this->actingAs($this->user)->delete(route('settings.memories.destroy', $theirs))->assertForbidden();
        $this->actingAs($this->user)->delete(route('settings.memories.destroy', $mine))->assertRedirect(route('settings.memories.index'));

        expect(UserMemory::pluck('id')->all())->toBe([$theirs->id]);
    });

    it('sends a guest to sign in', function () {
        $this->get(route('settings.memories.index'))->assertRedirect(route('login'));
    });
});

it('isolates memories by tenant (Exercise 4)', function () {
    [$a, $b] = Tenant::factory(2)->create();

    $alice = User::factory()->inTenant($a)->create();
    $bob = User::factory()->inTenant($b)->create();

    remember($alice, 'role', 'secret-alpha-4471');
    remember($bob, 'role', 'secret-beta-9920');

    actingAsTenant($b);

    expect(UserMemory::pluck('value')->all())->toBe(['secret-beta-9920'])
        ->and(fn () => UserMemory::where('user_id', $alice->id)->firstOrFail())->toThrow(ModelNotFoundException::class)
        ->and(MemoryBlock::render($alice))->toBe('');
});
