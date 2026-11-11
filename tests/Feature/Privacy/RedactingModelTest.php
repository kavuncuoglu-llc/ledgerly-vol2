<?php

use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Privacy\Ner;
use App\Ai\Privacy\Span;
use App\Ai\Privacy\VaultFactory;
use App\Ai\Tools\ChangeBillingEmail\ChangeBillingEmail;
use App\Ai\Tools\OpenTicket\OpenTicket;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolGate;
use App\Events\TicketReceived;
use App\Facades\Ai;
use App\Models\PendingToolCall;
use App\Models\ToolCall;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeNer;
use Tests\Support\Otel;

// Chapter 34, Topic 9: the decorator in AiManager's chain. 'hosted' stands in for any provider whose privacy block says redact; 'local' is the role that never redacts.
beforeEach(function () {
    config(['ai.providers.hosted' => ['driver' => 'fake', 'privacy' => ['redact' => true]]]);

    $this->ner = FakeNer::knowing(['Dana Whitfield' => 'PERSON']);

    app()->instance(Ner::class, $this->ner);

    $this->ai = Ai::fake();

    $this->ask = fn (string $question, array $extra = [], string $provider = 'hosted') => Ai::chat($provider)->chat(ChatRequest::make(
        [['role' => 'system', 'content' => 'You are the support assistant.'], ['role' => 'user', 'content' => $question]],
        ['conversation_id' => 14, ...$extra],
        'support.reply',
    ));

    $this->received = fn () => collect($this->ai->sent())->last()['request']->messages;
})->group('privacy');

afterEach(fn () => isset($this->otel) && $this->otel->detach());

describe('what the provider receives', function () {
    it('turns a Luhn-valid card number into <CARD_1> and leaves a Luhn-invalid 16-digit string alone', function () {
        $this->ai->for('support.reply')->respondWith('Thanks.');

        ($this->ask)('My card is 4242 4242 4242 4242 but order 4242 4242 4242 4243 is late');

        expect(($this->received)()[1]['content'])->toBe('My card is <CARD_1> but order 4242 4242 4242 4243 is late');
    });

    it('tokenizes an SSN, an email, a phone number and an IBAN, and restores them in the reply', function () {
        $this->ai->for('support.reply')->respondWith('I will use <EMAIL_1>, call <PHONE_1>, refund to <IBAN_1>; <SSN_1> is on file.');

        $response = ($this->ask)('Mail dana@acme.example, call +1 415 555 0142, refund to DE89 3704 0044 0532 0130 00, SSN 123-45-6789.');

        expect(($this->received)()[1]['content'])->toBe('Mail <EMAIL_1>, call <PHONE_1>, refund to <IBAN_1>, SSN <SSN_1>.')
            ->and($response->content)->toBe('I will use dana@acme.example, call +1 415 555 0142, refund to DE89 3704 0044 0532 0130 00; 123-45-6789 is on file.');
    });

    it('turns "Dana Whitfield" in a ticket into <PERSON_1> (the name detector)', function () {
        $this->ai->for('support.reply')->respondWith('Hello <PERSON_1>.');

        $response = ($this->ask)('This is Dana Whitfield from Acme about INV-4471.');

        expect(($this->received)()[1]['content'])->toBe('This is <PERSON_1> from Acme about INV-4471.')
            ->and($response->content)->toBe('Hello Dana Whitfield.');
    });

    it('never redacts invoice #4471 or ticket T-2291', function () {
        $this->ai->for('support.reply')->respondWith('ok');

        ($this->ask)('Send the receipt for #4471 and INV-4471 about ticket T-2291, 2026-02-01, $1,200.00');

        expect(($this->received)()[1]['content'])->toBe('Send the receipt for #4471 and INV-4471 about ticket T-2291, 2026-02-01, $1,200.00');
    });

    it('gives the same value the same placeholder across calls in one conversation, and a stranger another vault', function () {
        $this->ai->for('support.reply')->respondWith('a', times: 3);

        ($this->ask)('write to dana@acme.example');
        ($this->ask)('and also sam@acme.example, then dana@acme.example again');
        ($this->ask)('write to dana@acme.example', ['conversation_id' => 99]);

        $contents = collect($this->ai->sent())->map(fn ($call) => $call['request']->messages[1]['content'])->all();

        expect($contents)->toBe(['write to <EMAIL_1>', 'and also <EMAIL_2>, then <EMAIL_1> again', 'write to <EMAIL_1>']);
    });

    it('redacts the whole history, assistant turns and tool results included, and leaves the system text alone', function () {
        $this->ai->for('support.reply')->respondWith('ok');

        Ai::chat('hosted')->chat(ChatRequest::make([
            ['role' => 'system', 'content' => 'You are the support assistant. Write to ops@example.org if stuck.'],
            ['role' => 'user', 'content' => 'My email is dana@acme.example'],
            ['role' => 'assistant', 'content' => 'Noted, dana@acme.example.', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'open_ticket', 'arguments' => '{"summary":"call +1 415 555 0142"}']]]],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => '{"contact":"sam@acme.example"}'],
        ], ['conversation_id' => 14], 'support.reply'));

        $messages = ($this->received)();

        expect($messages[0]['content'])->toBe('You are the support assistant. Write to ops@example.org if stuck.')
            ->and($messages[1]['content'])->toBe('My email is <EMAIL_1>')
            ->and($messages[2]['content'])->toBe('Noted, <EMAIL_1>.')
            ->and($messages[2]['tool_calls'][0]['function']['arguments'])->toBe('{"summary":"call <PHONE_1>"}')
            ->and($messages[3]['content'])->toBe('{"contact":"<EMAIL_2>"}');
    });

    it('redacts the memory block inside the system message and nothing else in it', function () {
        $this->ai->for('support.reply')->respondWith('ok');

        $system = "You are the support assistant.\n\nWhat you know about this customer (they can edit this in Settings):\n- billing_contact: billing@acme.example\n- preferred_name: Dana Whitfield";

        Ai::chat('hosted')->chat(ChatRequest::make([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => 'hi']], ['conversation_id' => 14], 'support.reply'));

        expect(($this->received)()[0]['content'])->toBe("You are the support assistant.\n\nWhat you know about this customer (they can edit this in Settings):\n- billing_contact: <EMAIL_1>\n- preferred_name: <PERSON_1>");
    });

    it('leaves the local role alone: the data never leaves the machine', function () {
        $this->ai->for('support.reply')->respondWith('ok');

        ($this->ask)('Mail dana@acme.example', provider: 'local');

        expect(($this->received)()[1]['content'])->toBe('Mail dana@acme.example');
    });
});

describe('what comes back', function () {
    it('restores a placeholder split across two streamed deltas, once, correctly', function () {
        $this->ai->for('support.reply')->respondWithStream(['I can write to <EMA', 'IL_1> today.']);

        $stream = Ai::chat('hosted')->stream(ChatRequest::make([['role' => 'user', 'content' => 'write to dana@acme.example']], ['conversation_id' => 14], 'support.reply'));

        $text = '';
        $deltas = 0;

        foreach ($stream as $event) {
            if ($event instanceof Delta) {
                $text .= $event->text;
                $deltas++;
            }
        }

        expect($text)->toBe('I can write to dana@acme.example today.')
            ->and(substr_count($text, 'dana@acme.example'))->toBe(1)
            ->and($stream->getReturn()->content)->toBe('I can write to dana@acme.example today.')
            ->and($deltas)->toBeGreaterThanOrEqual(2);
    });

    it('restores a mangled placeholder (EMAIL_1) and logs it', function () {
        Log::spy();

        $this->ai->for('support.reply')->respondWith('Sure, EMAIL_1 it is.');

        $response = ($this->ask)('write to dana@acme.example');

        expect($response->content)->toBe('Sure, dana@acme.example it is.');

        Log::shouldHaveReceived('warning')->with('redaction.placeholder_mangled', Mockery::any())->once();
    });

    it('does not restore tool arguments in the response', function () {
        $this->ai->for('support.reply')->respondWithToolCall('change_billing_email', ['new_email' => '<EMAIL_1>']);

        $response = ($this->ask)('please change it to dana@acme.example');

        expect($response->toolCalls[0]['function']['arguments'])->toBe('{"new_email":"<EMAIL_1>"}');
    });
});

describe('the tool gate', function () {
    beforeEach(function () {
        Event::fake([TicketReceived::class]);

        [$this->user, $this->invoice, $this->customer] = customerWithInvoice('INV-4471');

        $conversation = conversationFor($this->user);

        $this->ctx = new ToolContext($this->user, $conversation->id);

        $text = 'dana@acme.example';

        app(VaultFactory::class)->for($conversation->id)->tokenize($text, [new Span('EMAIL', 0, strlen($text), $text)]);
    });

    it('does not restore <EMAIL_1> for a tool that does not accept vault references', function () {
        $result = app(ToolGate::class)->dispatch(new OpenTicket, ['summary' => 'Please write to <EMAIL_1> about this', 'invoice_number' => null], $this->ctx);

        expect($result->ok)->toBeTrue()
            ->and(DB::table('tickets')->latest('id')->value('summary'))->toContain('<EMAIL_1>')
            ->and(json_encode(DB::table('tickets')->latest('id')->first()))->not->toContain('dana@acme.example');
    });

    it('restores it for a tool that declares it, shows the real address on the card, and audits the placeholder', function () {
        $result = app(ToolGate::class)->dispatch(new ChangeBillingEmail, ['new_email' => '<EMAIL_1>'], $this->ctx);

        $pending = PendingToolCall::latest('id')->first();

        expect($result->isPending())->toBeTrue()
            ->and($pending->arguments)->toBe(['new_email' => 'dana@acme.example'])
            ->and($pending->summary)->toContain('dana@acme.example')
            ->and(ToolCall::latest('id')->first()->arguments)->toBe(['new_email' => '<EMAIL_1>']);
    });

    it('leaves the placeholder when the vault has expired, and the tool refuses it', function () {
        app(VaultFactory::class)->forget($this->ctx->conversationId);

        $result = app(ToolGate::class)->dispatch(new ChangeBillingEmail, ['new_email' => '<EMAIL_1>'], $this->ctx);

        expect($result->ok)->toBeFalse()
            ->and(PendingToolCall::count())->toBe(0);
    });
});

describe('failing closed', function () {
    it('reroutes a hosted call to local when the name detector is down, and says so on the span', function () {
        $this->otel = Otel::start();

        $this->ner->up = false;

        $this->ai->for('support.reply')->respondWith('Answered locally.');

        $response = ($this->ask)('Mail dana@acme.example, I am Dana Whitfield');

        expect($response->content)->toBe('Answered locally.');

        $this->ai->assertNothingSentTo('hosted');

        $this->ai->assertSent(fn (ChatRequest $request, string $provider) => $provider === 'local' && $request->messages[1]['content'] === 'Mail dana@acme.example, I am Dana Whitfield');

        expect(collect($this->otel->spans())->contains(fn ($span) => ($span->getAttributes()->toArray()['ledgerly.redaction.rerouted'] ?? null) === 'local'))->toBeTrue();
    });

    it('reroutes when the detector goes down in the middle of a call, streamed too', function () {
        $this->ai->for('support.reply')->respondWithStream(['Answered ', 'locally.']);

        $this->ner->failsWhenAsked = true;

        $stream = Ai::chat('hosted')->stream(ChatRequest::make([['role' => 'user', 'content' => 'hello there']], ['conversation_id' => 14], 'support.reply'));

        $text = '';

        foreach ($stream as $event) {
            $text .= $event instanceof Delta ? $event->text : '';
        }

        expect($text)->toBe('Answered locally.');

        $this->ai->assertNothingSentTo('hosted');
    });

    it('still runs the pattern detectors on the local role when the name detector is down', function () {
        $this->ner->up = false;

        config(['ai.providers.local' => ['driver' => 'fake', 'privacy' => ['redact' => true]]]);

        $this->ai->for('support.reply')->respondWith('ok');

        ($this->ask)('Mail dana@acme.example, I am Dana Whitfield', provider: 'local');

        expect(($this->received)()[1]['content'])->toBe('Mail <EMAIL_1>, I am Dana Whitfield');
    });
});
