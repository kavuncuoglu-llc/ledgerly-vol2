<?php

use App\Livewire\Chat;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function () {
    Sleep::fake();

    config([
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
    ]);

    [$this->user] = customerWithInvoice();
});

describe('the plain page', function () {
    it('hands the browser the Reverb settings, never the secret, and loads the no-build scripts', function () {
        $response = $this->actingAs($this->user)->get('support')->assertOk();

        $response->assertSee('name="reverb-config"', false)
            ->assertSee(asset('js/echo-lite.js'), false)
            ->assertSee(asset('js/reply-follow.js'), false)
            ->assertDontSee(config('broadcasting.connections.reverb.secret'));

        preg_match('/<meta name="reverb-config" content="([^"]+)"/', $response->getContent(), $found);

        $settings = json_decode(html_entity_decode($found[1]), true);

        expect($settings)->toBe(['key' => 'test-key', 'host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'authEndpoint' => '/broadcasting/auth']);
    });

    it('needs no Node: the scripts it loads are plain files in public/js', function () {
        expect(file_exists(public_path('js/echo-lite.js')))->toBeTrue()
            ->and(file_exists(public_path('js/reply-follow.js')))->toBeTrue()
            ->and(file_exists(base_path('package.json')))->toBeFalse();
    });

    it('turns away a guest from the Livewire page too', function () {
        $this->get('support/chat')->assertRedirect(route('login'));
    });

    it('renders the Livewire page for a signed-in customer', function () {
        $this->actingAs($this->user)->get('support/chat')->assertOk()->assertSeeLivewire(Chat::class);
    });
});

describe('the Livewire chat', function () {
    it('queues the question, shows it in the thread and tells Alpine which reply to follow', function () {
        Queue::fake();

        $component = Livewire::actingAs($this->user)
            ->test(Chat::class)
            ->set('question', 'What is a late fee?')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('question', '');

        $reply = Message::query()->where('role', 'assistant')->sole();

        $component->assertDispatched('reply-started', replyId: $reply->id);

        expect($reply->status)->toBe('pending');
    });

    it('shows the finished record, not the stream', function () {
        Http::fake(['*/chat/completions' => streamReply(['A late fee is ', 'a charge.'])]);

        Livewire::actingAs($this->user)
            ->test(Chat::class)
            ->set('question', 'What is a late fee?')
            ->call('send')
            ->assertSee('What is a late fee?')
            ->assertSee('A late fee is a charge.');
    });

    it('validates the question', function () {
        Livewire::actingAs($this->user)->test(Chat::class)->set('question', '')->call('send')->assertHasErrors(['question' => 'required']);

        Livewire::actingAs($this->user)->test(Chat::class)->set('question', str_repeat('a', 2001))->call('send')->assertHasErrors(['question' => 'max']);
    });

    it('will not let the browser point the component at somebody else\'s conversation', function () {
        $theirs = conversationFor(User::factory()->inTenant($this->user->tenant)->create());

        expect(fn () => Livewire::actingAs($this->user)->test(Chat::class)->set('conversationId', $theirs->id))
            ->toThrow(Exception::class, 'Cannot update locked property');
    });

    it('keeps staff out: they have no conversations', function () {
        Livewire::actingAs(User::factory()->support()->create())
            ->test(Chat::class)
            ->assertStatus(403);

        $this->actingAs(User::factory()->support()->create())->get('support/chat')->assertForbidden();

        expect(Conversation::count())->toBe(0);
    });
});
