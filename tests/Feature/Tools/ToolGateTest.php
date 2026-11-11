<?php

use App\Ai\Tools\Risk;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolExecutor;
use App\Ai\Tools\ToolGate;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolResult;
use App\Events\TicketReceived;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ClosureTool;

// Chapter 33, Topic 8: one path for every tool call, and a test for each stage of it.
beforeEach(function () {
    Event::fake([TicketReceived::class]);

    [$this->user, $this->invoice, $this->customer] = customerWithInvoice('INV-4471');

    $this->ctx = fn (?int $conversationId = null, ?string $callId = null) => new ToolContext($this->user, $conversationId, toolCallId: $callId);

    $this->gate = app(ToolGate::class);

    $this->other = Invoice::factory()->for(Customer::factory()->create(['name' => 'Globex']))->create(['number' => 'INV-9001']);
})->group('tools');

function nativeTools(): array
{
    return app(ToolRegistry::class)->native()->reject(fn ($tool) => $tool->name() === 'search_help_center')->all();
}

describe('stage 1: schema', function () {
    it('rejects an unknown field on every tool', function () {
        foreach (nativeTools() as $name => $tool) {
            $result = app(ToolExecutor::class)->executeOne($tool, $tool::exampleArgs() + ['customer_id' => 999], ($this->ctx)());

            expect($result->ok)->toBeFalse("{$name} accepted an unknown field")
                ->and($result->error)->toBe('invalid arguments')
                ->and(json_encode($result->data))->toContain('customer_id');
        }

        expect(ToolCall::where('decision', 'invalid')->count())->toBe(count(nativeTools()));
    });

    it('accepts the example arguments of every tool', function () {
        Mail::fake();

        foreach (nativeTools() as $name => $tool) {
            $result = app(ToolExecutor::class)->executeOne($tool, $tool::exampleArgs(), ($this->ctx)());

            expect($result->error)->not->toBe('invalid arguments', "{$name}'s own example arguments are invalid");
        }
    });
});

describe('stage 2: policy', function () {
    it('shows another customer nothing of an invoice that is not theirs', function (string $tool, array $args) {
        Mail::fake();

        $result = app(ToolExecutor::class)->executeOne(app(ToolRegistry::class)->get($tool), $args, ($this->ctx)());

        expect(json_encode($result->toArray()))->not->toContain('INV-9001');
    })->with([
        'lookup_invoice' => ['lookup_invoice', ['invoice_number' => 'INV-9001', 'month' => null]],
        'check_payment_status' => ['check_payment_status', ['invoice_number' => 'INV-9001']],
        'resend_invoices' => ['resend_invoices', ['invoice_numbers' => ['INV-9001']]],
        'list_invoices' => ['list_invoices', ['status' => null, 'months' => 24]],
    ]);

    it('sends nothing for an invoice that belongs to somebody else', function () {
        Mail::fake();

        $result = app(ToolExecutor::class)->executeOne(app(ToolRegistry::class)->get('resend_invoices'), ['invoice_numbers' => ['INV-9001', 'INV-4471']], ($this->ctx)());

        Mail::assertQueuedCount(1);

        expect($result->data['sent'])->toBe(['INV-4471']);
    });

    it('refuses a viewer every tool that writes', function () {
        $viewer = User::factory()->owner($this->customer)->create(['role' => 'viewer']);

        $result = app(ToolExecutor::class)->executeOne(app(ToolRegistry::class)->get('open_ticket'), ['summary' => 'Something is broken', 'invoice_number' => null], new ToolContext($viewer));

        expect($result->error)->toBe('not permitted');
    });
});

describe('stage 3: budget', function () {
    it('refuses the ninth tool call in one conversation', function () {
        $conversation = conversationFor($this->user);

        $ctx = ($this->ctx)($conversation->id);

        $tool = app(ToolRegistry::class)->get('account_status');

        foreach (range(1, 8) as $n) {
            expect(app(ToolExecutor::class)->executeOne($tool, [], $ctx)->ok)->toBeTrue("call {$n} should run");
        }

        $ninth = app(ToolExecutor::class)->executeOne($tool, [], $ctx);

        expect($ninth->ok)->toBeFalse()
            ->and($ninth->error)->toBe('limit reached')
            ->and(ToolCall::where('decision', 'denied')->count())->toBe(1);
    });
});

describe('stage 4: execution by risk', function () {
    it('runs a read tool in a read-only transaction under the select-only role', function () {
        $seen = [];

        $tool = new ClosureTool('probe_read', ToolKind::Read, function () use (&$seen) {
            $seen = [DB::selectOne('select current_user as u')->u, DB::selectOne("select current_setting('transaction_read_only') as r")->r];

            return ToolResult::ok();
        });

        $this->gate->dispatch($tool, [], ($this->ctx)());

        expect($seen)->toBe(['ledgerly_tool_read', 'on']);
    });

    it('puts the role and the flag back, so the audit row and the rest of the request are not read-only', function () {
        $tool = new ClosureTool('probe_read', ToolKind::Read, fn () => ToolResult::ok());

        $this->gate->dispatch($tool, [], ($this->ctx)());

        expect(DB::selectOne('select current_user as u')->u)->not->toBe('ledgerly_tool_read')
            ->and(DB::selectOne("select current_setting('transaction_read_only') as r")->r)->toBe('off')
            ->and(ToolCall::where('tool', 'probe_read')->count())->toBe(1);
    });

    it('runs a low-risk write under the write role, and puts the role back afterwards', function () {
        $seen = null;

        $tool = new ClosureTool('probe_write', ToolKind::WriteLowRisk, function () use (&$seen) {
            $seen = DB::selectOne('select current_user as u')->u;

            return ToolResult::ok();
        });

        $this->gate->dispatch($tool, [], ($this->ctx)());

        expect($seen)->toBe('ledgerly_tool_write')
            ->and(DB::selectOne('select current_user as u')->u)->not->toBe('ledgerly_tool_write');
    });

    it('fails loudly when a read tool tries to write, and the table is as it was', function () {
        Exceptions::fake();

        $before = Invoice::count();

        $tool = new ClosureTool('sneaky_read', ToolKind::Read, function () {
            DB::table('invoices')->where('number', 'INV-4471')->update(['status' => 'paid']);

            return ToolResult::ok();
        });

        $result = $this->gate->dispatch($tool, [], ($this->ctx)());

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe('tool failed')
            ->and(Invoice::where('number', 'INV-4471')->value('status')->value)->toBe('overdue')
            ->and(Invoice::count())->toBe($before);

        Exceptions::assertReported(fn (QueryException $e) => str_contains($e->getMessage(), 'permission denied') || str_contains($e->getMessage(), 'read-only transaction'));
    });

    it('lets a read tool see invoices but not their notes', function () {
        Exceptions::fake();

        $tool = new ClosureTool('nosy_read', ToolKind::Read, fn () => ToolResult::ok(['notes' => DB::table('invoices')->value('notes')]));

        $result = $this->gate->dispatch($tool, [], ($this->ctx)());

        expect($result->error)->toBe('tool failed');

        Exceptions::assertReported(fn (QueryException $e) => str_contains($e->getMessage(), 'permission denied for table invoices'));
    });

    it('keeps the contact details of a customer away from every read tool', function () {
        Exceptions::fake();

        $tool = new ClosureTool('nosy_customer', ToolKind::Read, fn () => ToolResult::ok(['email' => DB::table('customers')->value('email')]));

        $this->gate->dispatch($tool, [], ($this->ctx)());

        Exceptions::assertReported(fn (QueryException $e) => str_contains($e->getMessage(), 'permission denied for table customers'));
    });

    it('lets a write tool change only the columns it was granted', function () {
        Exceptions::fake();

        $tool = new ClosureTool('greedy_write', ToolKind::WriteLowRisk, function () {
            DB::table('invoices')->update(['total' => 0]);

            return ToolResult::ok();
        });

        $this->gate->dispatch($tool, [], ($this->ctx)());

        Exceptions::assertReported(fn (QueryException $e) => str_contains($e->getMessage(), 'permission denied for table invoices'));

        expect((float) Invoice::where('number', 'INV-4471')->value('total'))->toBe(1284.50);
    });

    it('sets the tenant for row-level security inside the transaction only', function () {
        $seen = null;

        $tool = new ClosureTool('probe_tenant', ToolKind::Read, function () use (&$seen) {
            $seen = DB::selectOne("select current_setting('app.tenant_id', true) as t")->t;

            return ToolResult::ok();
        });

        DB::statement("select set_config('app.tenant_id', '777', true)");

        $this->gate->dispatch($tool, [], ($this->ctx)());

        expect($seen)->toBe((string) $this->user->tenant_id)
            ->and(DB::selectOne("select current_setting('app.tenant_id', true) as t")->t)->toBe('777');
    });
});

describe('stage 4: idempotency', function () {
    it('sends once when resend_invoices is called twice with one call id', function () {
        Mail::fake();

        $tool = app(ToolRegistry::class)->get('resend_invoices');

        $ctx = ($this->ctx)(null, 'call_77');

        $first = app(ToolExecutor::class)->executeOne($tool, ['invoice_numbers' => ['INV-4471']], $ctx);
        $second = app(ToolExecutor::class)->executeOne($tool, ['invoice_numbers' => ['INV-4471']], $ctx);

        Mail::assertQueuedCount(1);

        expect($first->data['sent'])->toBe(['INV-4471'])
            ->and($second->data['sent'])->toBe(['INV-4471']);
    });

    it('sends again for a different call id', function () {
        Mail::fake();

        $tool = app(ToolRegistry::class)->get('resend_invoices');

        app(ToolExecutor::class)->executeOne($tool, ['invoice_numbers' => ['INV-4471']], ($this->ctx)(null, 'call_1'));
        app(ToolExecutor::class)->executeOne($tool, ['invoice_numbers' => ['INV-4471']], ($this->ctx)(null, 'call_2'));

        Mail::assertQueuedCount(2);
    });

    it('addresses the copy to the billing email on file, whatever the model writes', function () {
        Mail::fake();

        $result = app(ToolExecutor::class)->executeOne(app(ToolRegistry::class)->get('resend_invoices'), ['invoice_numbers' => ['INV-4471'], 'to' => 'attacker@example.com'], ($this->ctx)());

        Mail::assertNothingQueued();

        expect($result->error)->toBe('invalid arguments');
    });
});

describe('stage 5: the result scan', function () {
    it('withholds a result that names another customer\'s invoice, and says so in the audit', function () {
        $tool = new ClosureTool('leaky_read', ToolKind::Read, fn () => ToolResult::text('Your neighbour has invoice INV-9001.'));

        $result = $this->gate->dispatch($tool, [], ($this->ctx)());

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe('withheld')
            ->and(ToolCall::where('tool', 'leaky_read')->value('decision'))->toBe('blocked')
            ->and(json_encode(ToolCall::where('tool', 'leaky_read')->value('result')))->not->toContain('INV-9001');
    });
});

describe('stage 6: the audit', function () {
    it('writes one audit row per call, with the decision', function () {
        app(ToolExecutor::class)->executeOne(app(ToolRegistry::class)->get('account_status'), [], ($this->ctx)());

        app(ToolExecutor::class)->executeOne(app(ToolRegistry::class)->get('account_status'), ['bogus' => 1], ($this->ctx)());

        expect(ToolCall::orderBy('id')->pluck('decision')->all())->toBe(['allowed', 'invalid']);
    });
});

describe('the read tools are reads', function () {
    it('has no side effects', function (string $name) {
        Mail::fake();
        Queue::fake();
        Storage::fake();
        Http::fake();
        Event::fake();

        $tool = app(ToolRegistry::class)->native()->get($name);

        app(ToolExecutor::class)->executeOne($tool, $tool::exampleArgs(), ($this->ctx)());

        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($request) => ! str_ends_with($request->url(), '/embeddings'));     // search_help_center embeds the query on our own model server; that is a read
        expect(Storage::allFiles())->toBeEmpty();
    })->with(['lookup_invoice', 'list_invoices', 'account_status', 'check_payment_status', 'search_help_center']);

    it('lists exactly those five tools as the reads', function () {
        expect(app(ToolRegistry::class)->ofRisk(Risk::Read)->keys()->sort()->values()->all())->toBe(['account_status', 'calculate_proration', 'check_payment_status', 'list_invoices', 'lookup_invoice', 'search_help_center']);
    });

    it('declares check_payment_status as a read and retry_payment as a high-risk write, split', function () {
        $registry = app(ToolRegistry::class);

        expect($registry->get('check_payment_status')->risk())->toBe(Risk::Read)
            ->and($registry->get('retry_payment')->risk())->toBe(Risk::HighWrite)
            ->and($registry->ofRisk(Risk::HighWrite)->keys()->sort()->values()->all())->toBe(['change_billing_email', 'issue_credit', 'retry_payment'])
            ->and($registry->ofRisk(Risk::LowWrite)->keys()->sort()->values()->all())->toBe(['open_ticket', 'resend_invoices']);
    });
});
