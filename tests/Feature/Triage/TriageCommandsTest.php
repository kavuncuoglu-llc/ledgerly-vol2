<?php

use App\Ai\Triage\TriagePipeline;
use App\Models\AgentRun;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\TriageRun;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;

function sampleScript(array $splitNeedles = []): array
{
    $samples = json_decode(file_get_contents(base_path('storage/samples/tickets.json')), true);

    $votes = [];

    foreach ($samples as $sample) {
        $votes[$sample['message']] = in_array($sample['message'], $splitNeedles, true)
            ? [$sample['label'], 'billing', $sample['label'] === 'account' ? 'bug' : 'account']
            : [$sample['label'], $sample['label'], $sample['label']];
    }

    return ['votes' => $votes];
}

beforeEach(function () {
    Sleep::fake();

    config(['ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.0]);

    $this->seed();

    $samples = json_decode(file_get_contents(base_path('storage/samples/tickets.json')), true);

    $this->ambiguous = collect($samples)->firstWhere('label', 'bug')['message'];
});

it('triages the twenty sample tickets: three to a person, the rest automated, the billing and account ones investigated', function () {
    triageWorld(sampleScript([$this->ambiguous]));

    $this->artisan('ticket:triage')
        ->expectsOutputToContain('20 tickets · 17 automated · 3 to human · 11 agent runs')
        ->assertSuccessful();

    expect(Ticket::where('id', '>', 10430)->count())->toBe(20)
        ->and(TriageRun::where('status', 'human')->count())->toBe(3)
        ->and(TriageRun::where('status', 'automated')->whereNotNull('agent_run_id')->count())->toBe(11)
        ->and(AgentRun::where('agent', 'triage')->count())->toBe(11)
        ->and(Ticket::where('id', '>', 10430)->whereNull('category')->count())->toBe(3);
});

it('prints one line per ticket with the category, the vote, the urgency, the assignee and the agent step', function () {
    triageWorld(sampleScript([$this->ambiguous]));

    Artisan::call('ticket:triage', ['--limit' => 6]);

    $output = Artisan::output();

    expect($output)->toMatch('/#1\s+billing\s+\(3\/3\)\s+\w+\s+→ billing-team\s+investigate: 1 rounds, 0 lookups\s+draft queued/')
        ->and($output)->toContain('6 tickets');
});

it('shows an ambiguous ticket as HUMAN with the reason and no draft', function () {
    triageWorld(sampleScript([$this->ambiguous]));

    Artisan::call('ticket:triage');

    expect(Artisan::output())->toMatch('/bug\s+\(1\/3\)\s+—\s+→ HUMAN\s+ambiguous category: bug\/billing\/account\s+no draft/');
});

it('writes tickets for the customer you name', function () {
    triageWorld(sampleScript());

    $customer = Customer::factory()->create();
    User::factory()->owner($customer)->create();

    $this->artisan("ticket:triage --customer={$customer->id} --limit=2")->assertSuccessful();

    expect(Ticket::where('customer_id', $customer->id)->count())->toBe(2);
});

it('fails clearly for a customer with nobody to act as, and for a missing file', function () {
    triageWorld();

    $this->artisan('ticket:triage --customer=999999')->assertFailed();
    $this->artisan('ticket:triage /nonexistent.json')->assertFailed();
});

describe('agent:trace', function () {
    beforeEach(function () {
        $customer = Customer::find(12);

        Invoice::whereNumber('INV-4471')->first()?->update([]);

        triageWorld([
            'votes' => ['invoice 4471' => ['billing', 'billing', 'billing']],
            'extract' => ['invoice 4471' => ['invoice_number' => '4471', 'category' => 'billing']],
            'steps' => [
                toolCallsBody([['c1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]]]),
                toolCallsBody([['c2', 'list_invoices', ['status' => null, 'months' => 3]]]),
                chatBody('INV-4471 is 18 days overdue.'),
            ],
        ]);

        $this->ticket = Ticket::create(['customer_id' => $customer->id, 'user_id' => $customer->owner->id, 'subject' => 'Client paid but invoice shows unpaid', 'body' => 'Client says they paid invoice 4471 but it shows unpaid', 'source' => 'form']);

        app(TriagePipeline::class)->run($this->ticket);
    });

    it('prints a run as a readable log: goal, decisions, results, budget and findings', function () {
        $run = AgentRun::where('agent', 'triage')->sole();

        $this->artisan("agent:trace {$run->id}")
            ->expectsOutputToContain("run {$run->id} · triage · done")
            ->expectsOutputToContain('goal: Gather the facts needed to resolve: Client paid but invoice shows unpaid')
            ->expectsOutputToContain('r1 decision → lookup_invoice(invoice_number: "4471", month: null)')
            ->expectsOutputToContain('r2 decision → list_invoices(status: null, months: 3)')
            ->expectsOutputToContain('r3 decision → done: INV-4471 is 18 days overdue.')
            ->expectsOutputToContain('budget: 3 rounds')
            ->expectsOutputToContain('findings: {"invoices":[{"number":"INV-4471"')
            ->assertSuccessful();
    });

    it('finds the run by ticket number, and defaults to the latest', function () {
        $this->artisan("agent:trace --ticket=T-{$this->ticket->id}")->expectsOutputToContain('lookup_invoice')->assertSuccessful();
        $this->artisan('agent:trace')->expectsOutputToContain('triage')->assertSuccessful();
    });

    it('says so when there is no such run', function () {
        $this->artisan('agent:trace 99999')->assertFailed();
        $this->artisan('agent:trace --ticket=T-1')->assertFailed();
    });

    it('shows the triage on the ticket page, with the draft marked as not sent', function () {
        $this->get("tickets/{$this->ticket->id}")
            ->assertOk()
            ->assertSee('Triage')
            ->assertSee('billing-team')
            ->assertSee('INV-4471 is 18 days overdue.')
            ->assertSee('not sent');
    });
});
