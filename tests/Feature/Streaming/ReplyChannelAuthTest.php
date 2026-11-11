<?php

use App\Ai\Conversations\ConversationStore;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    app(BroadcastManager::class)->purge('reverb');
    app(BroadcastManager::class)->purge('null');

    require base_path('routes/channels.php');                                      // register the channel on the driver the test just switched to

    [$this->owner] = customerWithInvoice();

    $this->reply = app(ConversationStore::class)->placeholder(conversationFor($this->owner), 'Why is my invoice late?');
});

function subscribe(string $channel, ?User $as = null)
{
    $request = $as === null
        ? test()
        : test()->actingAs($as);

    return $request->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-{$channel}"]);
}

it('signs a subscription for the person who asked', function () {
    subscribe("reply.{$this->reply->id}", $this->owner)
        ->assertOk()
        ->assertJsonPath('auth', fn (string $auth) => str_starts_with($auth, 'test-key:'));
});

it('refuses a colleague in the same tenant: replies are per person', function () {
    $colleague = User::factory()->inTenant($this->owner->tenant)->create();

    subscribe("reply.{$this->reply->id}", $colleague)->assertForbidden();
});

it('refuses a user from another tenant', function () {
    [$stranger] = customerWithInvoice('INV-9001');

    subscribe("reply.{$this->reply->id}", $stranger)->assertForbidden();
});

it('refuses Ledgerly staff, who have no tenant and no assistant conversations', function () {
    subscribe("reply.{$this->reply->id}", User::factory()->support()->create())->assertForbidden();
});

it('refuses a guest', function () {
    subscribe("reply.{$this->reply->id}")->assertForbidden();
});

it('refuses a message that is not a reply placeholder, even the owner\'s own question', function () {
    $question = Message::query()->create([
        'conversation_id' => $this->reply->conversation_id,
        'tenant_id' => $this->reply->tenant_id,
        'seq' => 99,
        'role' => 'user',
        'content' => 'A question',
        'tokens' => 3,
    ]);

    subscribe("reply.{$question->id}", $this->owner)->assertForbidden();
});

it('refuses a reply that does not exist', function () {
    subscribe('reply.999999', $this->owner)->assertForbidden();
});

it('does not put a customer\'s data in the channel name', function () {
    expect("reply.{$this->reply->id}")->toMatch('/^reply\.\d+$/');
});
