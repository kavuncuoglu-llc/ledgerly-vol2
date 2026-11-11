<?php

use App\Ai\Contracts\ChatRequest;
use App\Ai\Schemas\Category;
use App\Ai\Triage\TriagePipeline;
use App\Facades\Ai;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    config(['ledgerly.help_version' => 'v1']);

    $this->customer = Customer::factory()->create(['plan' => 'pro']);
    $this->owner = User::factory()->owner($this->customer)->create();

    Invoice::factory()->for($this->customer)->overdue(18)->withLateFee()->create(['number' => 'INV-4471', 'subtotal' => 1200, 'tax' => 84.50, 'total' => 1284.50]);
});

function frontierIsSetUp(): void
{
    config([
        'ai.providers.frontier.base_url' => 'https://frontier.example.com/v1',
        'ai.providers.frontier.api_key' => 'sk-test-frontier-key',
        'ai.providers.frontier.model' => 'frontier-example',
    ]);
}

describe('against Ai::fake() (Figure 19-6)', function () {
    it('routes a billing ticket through investigation and drafts a reply, with no model and no HTTP', function () {
        Ai::fake()
            ->for('ticket.classify')->respondWith(ticketJson('billing'), times: 3)
            ->for('ticket.extract')->respondWithJson(['category' => 'billing', 'invoice_number' => '4471', 'customer_email' => null, 'amount_mentioned' => null, 'date_mentioned' => null])
            ->for('triage.investigate')->respondWithToolCall('lookup_invoice', ['invoice_number' => '4471', 'month' => null])
            ->for('triage.investigate')->respondWith('Findings gathered.')
            ->for('triage.draft')->respondWith('Hi, invoice #4471 shows as overdue. A specialist will check the payment.');

        $ticket = triageTicket(['subject' => 'Payment shows unpaid', 'body' => 'My client paid but invoice 4471 still shows unpaid'], $this->customer);

        $result = app(TriagePipeline::class)->run($ticket);

        expect($result->human)->toBeFalse()
            ->and($result->run->category)->toBe(Category::Billing->value)
            ->and($result->findings['invoices'][0]['number'])->toBe('INV-4471')
            ->and($result->draft->text)->toContain('#4471');

        Ai::assertSent(fn ($request) => $request->feature === 'ticket.classify' && $request->profile === 'assistant' && $request->options['temperature'] === 0.7);   // the three votes must differ, so Chapter 17 samples them

        Ai::assertSentCount('ticket.classify', 3);
        Ai::assertSentCount('triage.investigate', 2);
        Ai::assertSentCount('triage.draft', 1);
        Ai::assertNothingSentTo('frontier');                                       // the cost rule

        Http::assertNothingSent();
    });
});

describe('two providers at once', function () {
    it('keeps every ticket on local when frontier is not set up, however urgent', function () {
        config(['ai.providers.frontier.base_url' => null, 'ai.providers.frontier.api_key' => null, 'ai.providers.frontier.model' => null]);

        $enterprise = Customer::factory()->create(['plan' => 'enterprise']);

        User::factory()->owner($enterprise)->create();

        triageWorld(['votes' => ['export' => ['bug', 'bug', 'bug']], 'extract' => ['export' => ['category' => 'bug']], 'draft' => 'A specialist will follow up on this ticket.']);

        $ticket = triageTicket(['subject' => 'Export is broken', 'body' => 'The PDF export is broken, this is urgent'], $enterprise);

        $result = app(TriagePipeline::class)->run($ticket);

        expect($result->run->escalated_to)->toBeNull()
            ->and(ModelCall::query()->distinct()->pluck('provider')->all())->toBe(['local']);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'frontier.example.com'));
    });

    it('sends the draft of an urgent bug to frontier, and everything else to local, and logs both names', function () {
        frontierIsSetUp();

        $enterprise = Customer::factory()->create(['plan' => 'enterprise']);

        User::factory()->owner($enterprise)->create();

        Http::fake(['frontier.example.com/*' => Http::response(chatBody('We are looking into the export problem and a specialist will follow up.', model: 'frontier-example'))]);

        triageWorld(['votes' => ['export' => ['bug', 'bug', 'bug']], 'extract' => ['export' => ['category' => 'bug']]]);

        $ticket = triageTicket(['subject' => 'Export is broken', 'body' => 'The PDF export is broken, this is urgent'], $enterprise);

        $result = app(TriagePipeline::class)->run($ticket);

        expect($result->run->escalated_to)->toBe('frontier')
            ->and($result->run->escalation_reason)->toBe('an urgent bug')
            ->and($result->draft->text)->toBe('We are looking into the export problem and a specialist will follow up.');

        $byProvider = ModelCall::query()->get()->groupBy('provider')->map->pluck('feature')->map(fn ($features) => $features->unique()->sort()->values()->all())->all();

        expect($byProvider['frontier'])->toBe(['triage.draft'])
            ->and($byProvider['local'])->toContain('ticket.classify', 'ticket.extract');
    });

    it('never sends the local key to the hosted provider or the hosted key to local', function () {
        frontierIsSetUp();

        $enterprise = Customer::factory()->create(['plan' => 'enterprise']);

        User::factory()->owner($enterprise)->create();

        Http::fake(['frontier.example.com/*' => Http::response(chatBody('A specialist will follow up.', model: 'frontier-example'))]);

        triageWorld(['votes' => ['export' => ['bug', 'bug', 'bug']], 'extract' => ['export' => ['category' => 'bug']]]);

        app(TriagePipeline::class)->run(triageTicket(['subject' => 'Export is broken', 'body' => 'The PDF export is broken, this is urgent'], $enterprise));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'frontier.example.com') && $request->hasHeader('Authorization', 'Bearer sk-test-frontier-key'));

        Http::assertNotSent(fn (Request $request) => ! str_contains($request->url(), 'frontier.example.com') && str_contains(json_encode($request->headers()), 'sk-test-frontier-key'));

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'frontier.example.com') && $request->hasHeader('Authorization', 'Bearer ollama'));
    });

    it('escalates an investigation that ran two rounds and reached no conclusion', function () {
        frontierIsSetUp();

        Http::fake(['frontier.example.com/*' => Http::response(chatBody('A specialist will take a closer look at invoice 4471.', model: 'frontier-example'))]);

        $lookup = toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]);

        triageWorld([
            'votes' => ['invoice 4471' => ['billing', 'billing', 'billing']],
            'extract' => ['invoice 4471' => ['invoice_number' => '4471', 'category' => 'billing']],
            'steps' => [$lookup, $lookup, $lookup, $lookup, $lookup, $lookup, $lookup, $lookup, $lookup],
        ]);

        $result = app(TriagePipeline::class)->run(triageTicket(['subject' => 'Payment shows unpaid', 'body' => 'My client paid but invoice 4471 still shows unpaid'], $this->customer));

        expect($result->agentRun->status)->toBe('exhausted')
            ->and($result->run->escalated_to)->toBe('frontier')
            ->and($result->run->escalation_reason)->toContain('without a conclusion');
    });

    it('leaves a routine ticket on local even when frontier is set up', function () {
        frontierIsSetUp();

        triageWorld(['votes' => ['invoice 4471' => ['billing', 'billing', 'billing']], 'extract' => ['invoice 4471' => ['invoice_number' => '4471', 'category' => 'billing']], 'steps' => [chatBody('INV-4471 is 18 days overdue.')], 'draft' => 'A specialist will check the payment.']);

        $result = app(TriagePipeline::class)->run(triageTicket(['subject' => 'Payment shows unpaid', 'body' => 'My client paid but invoice 4471 still shows unpaid'], $this->customer));

        expect($result->run->escalated_to)->toBeNull();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'frontier.example.com'));
    });

    it('prints how many tickets went to frontier in the triage summary', function () {
        frontierIsSetUp();

        $enterprise = Customer::factory()->create(['plan' => 'enterprise']);

        User::factory()->owner($enterprise)->create();

        Http::fake(['frontier.example.com/*' => Http::response(chatBody('A specialist will follow up.', model: 'frontier-example'))]);

        triageWorld(['default_label' => 'bug', 'extract' => []]);

        $path = sys_get_temp_dir().'/triage-'.bin2hex(random_bytes(3)).'.json';

        file_put_contents($path, json_encode([['message' => 'The PDF export is broken, this is urgent', 'label' => 'bug', 'expect' => []]]));

        $this->artisan('ticket:triage', ['path' => $path, '--customer' => $enterprise->id])->expectsOutputToContain('1 escalated')->assertSuccessful();

        unlink($path);
    });
});

describe('the frontier cost rule', function () {
    it('records the frontier rates from the environment-driven config, as dollars per million tokens', function () {
        config(['ai.rates.frontier' => ['*' => ['in' => 3.0, 'out' => 15.0]]]);

        frontierIsSetUp();

        Http::fake(['*/chat/completions' => Http::response(chatBody('ok', usage: ['prompt_tokens' => 1000, 'completion_tokens' => 100], model: 'frontier-example'))]);

        Ai::chat('frontier')->chat(ChatRequest::make([['role' => 'user', 'content' => 'hi']], [], 'support.reply'));

        expect(ModelCall::sole()->cost_micros)->toBe(4500);                          // 1000 × 3 + 100 × 15 micro-dollars
    });
});
