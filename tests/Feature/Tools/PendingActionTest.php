<?php

use App\Ai\Tools\PendingAction;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolExecutor;
use App\Ai\Tools\ToolRegistry;
use App\Events\TicketReceived;
use App\Mail\BillingEmailChangeRequested;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PendingToolCall;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

// Chapter 33, Topic 6: every way a confirmation can be misused, and each one leaves the database exactly as it was.
function requestAction(string $tool, array $args, User $user): PendingToolCall
{
    $result = app(ToolExecutor::class)->executeOne(app(ToolRegistry::class)->get($tool), $args, new ToolContext($user));

    expect($result->isPending())->toBeTrue();

    return $result->pending->fresh();
}

function sideEffects(): array
{
    return [DB::table('payment_retries')->count(), DB::table('invoice_credits')->count(), DB::table('customers')->whereNotNull('billing_email_pending')->count()];
}

beforeEach(function () {
    Event::fake([TicketReceived::class]);

    [$this->user, $this->invoice, $this->customer] = customerWithInvoice('INV-4471');

    $this->approve = fn (PendingToolCall $pending, ?User $as = null, ?string $token = null) => test()->actingAs($as ?? $this->user)
        ->postJson(route('support.approve', $pending), ['token' => $token ?? PendingAction::tokenFor($pending->fresh())]);
})->group('tools');

describe('a high-risk tool', function () {
    it('writes a pending action instead of running, with a card written by the tool\'s code', function () {
        $pending = requestAction('retry_payment', ['invoice_number' => '4471'], $this->user);

        expect(sideEffects())->toBe([0, 0, 0])
            ->and($pending->status)->toBe('pending')
            ->and($pending->approver)->toBe('customer')
            ->and($pending->summary)->toBe(sprintf('Retry the payment on invoice INV-4471 (total $1,284.50) for %s. The card or bank account on file is charged again.', $this->customer->name))
            ->and($pending->args_hash)->toBe(hash('sha256', json_encode(['invoice_number' => '4471'])))
            ->and($pending->customer_id)->toBe($this->customer->id)
            ->and($pending->expires_at->isFuture())->toBeTrue();
    });

    it('never puts the token in anything the model sees', function () {
        $result = app(ToolExecutor::class)->executeOne(app(ToolRegistry::class)->get('retry_payment'), ['invoice_number' => '4471'], new ToolContext($this->user));

        expect(json_encode($result->toArray()))->not->toContain(PendingAction::tokenFor($result->pending))
            ->and($result->toJson())->not->toContain('token');
    });

    it('runs after the customer approves, under the write role, once', function () {
        $pending = requestAction('retry_payment', ['invoice_number' => '4471'], $this->user);

        ($this->approve)($pending)->assertOk()->assertJson(['status' => 'approved']);

        expect(sideEffects())->toBe([1, 0, 0])
            ->and($pending->fresh()->approved_by)->toBe($this->user->id)
            ->and($pending->fresh()->used_at)->not->toBeNull();
    });
});

describe('every misuse leaves the database as it was', function () {
    it('403 for the wrong approver: another customer\'s owner with the right token', function () {
        $pending = requestAction('retry_payment', ['invoice_number' => '4471'], $this->user);

        $stranger = User::factory()->owner(Customer::factory()->create())->create();

        ($this->approve)($pending, $stranger)->assertForbidden();

        expect(sideEffects())->toBe([0, 0, 0])
            ->and($pending->fresh()->status)->toBe('pending');
    });

    it('403 for a wrong token', function () {
        $pending = requestAction('retry_payment', ['invoice_number' => '4471'], $this->user);

        ($this->approve)($pending, null, str_repeat('0', 64))->assertForbidden();

        expect(sideEffects())->toBe([0, 0, 0]);
    });

    it('403 for a viewer of the same customer', function () {
        $pending = requestAction('retry_payment', ['invoice_number' => '4471'], $this->user);

        $viewer = User::factory()->owner($this->customer)->create(['role' => 'viewer']);

        ($this->approve)($pending, $viewer)->assertForbidden();

        expect(sideEffects())->toBe([0, 0, 0]);
    });

    it('410 after the expiry', function () {
        $pending = requestAction('retry_payment', ['invoice_number' => '4471'], $this->user);

        $this->travel(16)->minutes();

        ($this->approve)($pending)->assertStatus(410);

        expect(sideEffects())->toBe([0, 0, 0]);
    });

    it('410 when the same token is used twice', function () {
        $pending = requestAction('retry_payment', ['invoice_number' => '4471'], $this->user);

        ($this->approve)($pending)->assertOk();

        ($this->approve)($pending)->assertStatus(410);

        expect(sideEffects())->toBe([1, 0, 0]);
    });

    it('409 when the stored arguments are not the ones the token was made for', function () {
        $pending = requestAction('retry_payment', ['invoice_number' => '4471'], $this->user);

        $other = Invoice::factory()->for($this->customer)->overdue(30)->create(['number' => 'INV-4999']);

        DB::table('pending_tool_actions')->where('id', $pending->id)->update(['arguments' => json_encode(['invoice_number' => $other->number])]);

        ($this->approve)($pending)->assertStatus(409);

        expect(sideEffects())->toBe([0, 0, 0])
            ->and($pending->fresh()->status)->toBe('pending');
    });

    it('re-validates against today\'s state: an invoice paid since the request is not retried', function () {
        $pending = requestAction('retry_payment', ['invoice_number' => '4471'], $this->user);

        $this->invoice->update(['status' => 'paid', 'paid_at' => now()]);

        ($this->approve)($pending)->assertOk()->assertJson(['answer' => 'invoice already paid']);

        expect(sideEffects())->toBe([0, 0, 0]);
    });

    it('lets a decline through without a token, and then nothing else', function () {
        $pending = requestAction('retry_payment', ['invoice_number' => '4471'], $this->user);

        $this->actingAs($this->user)->postJson(route('support.decline', $pending))->assertOk();

        ($this->approve)($pending)->assertStatus(410);

        expect(sideEffects())->toBe([0, 0, 0]);
    });
});

describe('credits go to a support agent, not to the customer', function () {
    beforeEach(function () {
        $this->args = ['invoice_number' => '4471', 'amount_cents' => 1800, 'reason' => 'Late fee charged while a payment was in transit'];

        $this->agent = User::factory()->support()->create();
    });

    it('is addressed to the agent queue, and the card says who asked and why', function () {
        $pending = requestAction('issue_credit', $this->args, $this->user);

        expect($pending->approver)->toBe('agent')
            ->and($pending->summary)->toBe(sprintf('Issue a $18.00 credit to invoice 4471 for %s. Reason given: Late fee charged while a payment was in transit', $this->customer->name));
    });

    it('cannot be approved by the customer who asked', function () {
        $pending = requestAction('issue_credit', $this->args, $this->user);

        ($this->approve)($pending)->assertForbidden();

        expect(sideEffects())->toBe([0, 0, 0]);
    });

    it('is listed for agents only', function () {
        requestAction('issue_credit', $this->args, $this->user);

        $this->actingAs($this->user)->getJson(route('staff.approvals.index'))->assertForbidden();

        $this->actingAs($this->agent)->getJson(route('staff.approvals.index'))->assertOk()->assertJsonCount(1, 'data');
    });

    it('is granted by an agent with the token, once, and records who', function () {
        $pending = requestAction('issue_credit', $this->args, $this->user);

        $this->actingAs($this->agent)->postJson(route('staff.approvals.approve', $pending), ['token' => PendingAction::tokenFor($pending)])->assertOk()->assertJson(['ok' => true]);

        $this->actingAs($this->agent)->postJson(route('staff.approvals.approve', $pending), ['token' => PendingAction::tokenFor($pending)])->assertStatus(410);

        expect(DB::table('invoice_credits')->count())->toBe(1)
            ->and(DB::table('invoice_credits')->value('approved_by'))->toBe($this->agent->id)
            ->and(DB::table('invoice_credits')->value('amount_cents'))->toBe(1800);
    });

    it('is refused by the policy before anyone is asked, when the invoice has no late fee to credit', function () {
        Invoice::factory()->for($this->customer)->create(['number' => 'INV-4500', 'subtotal' => 500, 'tax' => 0, 'total' => 500]);

        $result = app(ToolExecutor::class)->executeOne(app(ToolRegistry::class)->get('issue_credit'), ['invoice_number' => 'INV-4500'] + $this->args, new ToolContext($this->user));

        expect($result->isPending())->toBeFalse()
            ->and($result->error)->toContain('goodwill credit policy does not allow')
            ->and(DB::table('pending_tool_actions')->count())->toBe(0)
            ->and(sideEffects())->toBe([0, 0, 0]);
    });

    it('is refused by the policy before anyone is asked, when the amount is above what the policy allows', function () {
        $result = app(ToolExecutor::class)->executeOne(app(ToolRegistry::class)->get('issue_credit'), ['amount_cents' => 100000] + $this->args, new ToolContext($this->user));

        expect($result->isPending())->toBeFalse()
            ->and($result->error)->toContain('allows at most 2160 cents')
            ->and(DB::table('pending_tool_actions')->count())->toBe(0);
    });

    it('can be declined by an agent', function () {
        $pending = requestAction('issue_credit', $this->args, $this->user);

        $this->actingAs($this->agent)->postJson(route('staff.approvals.decline', $pending))->assertOk();

        expect($pending->fresh()->status)->toBe('declined')
            ->and(sideEffects())->toBe([0, 0, 0]);
    });
});

describe('changing the billing email', function () {
    it('asks the customer, then asks the old address, and only then changes it', function () {
        Mail::fake();

        $old = $this->customer->email;

        $pending = requestAction('change_billing_email', ['new_email' => 'Accounts@Example.com'], $this->user);

        expect($pending->summary)->toContain($old)->toContain('accounts@example.com')->toContain('A confirmation is sent to the old address first');

        ($this->approve)($pending)->assertOk();

        Mail::assertQueued(BillingEmailChangeRequested::class, fn ($mail) => $mail->hasTo($old) && $mail->newEmail === 'accounts@example.com');

        expect($this->customer->fresh()->email)->toBe($old)
            ->and($this->customer->fresh()->billing_email_pending)->toBe('accounts@example.com');

        $link = null;

        Mail::assertQueued(BillingEmailChangeRequested::class, function ($mail) use (&$link) {
            $link = $mail->confirmUrl;

            return true;
        });

        $this->get($link)->assertOk();

        expect($this->customer->fresh()->email)->toBe('accounts@example.com')
            ->and($this->customer->fresh()->billing_email_pending)->toBeNull();
    });

    it('refuses an unsigned confirmation link', function () {
        $this->get(route('billing-email.confirm', $this->customer))->assertForbidden();
    });

    it('rejects an address that is not an address', function () {
        $result = app(ToolExecutor::class)->executeOne(app(ToolRegistry::class)->get('change_billing_email'), ['new_email' => 'not an address'], new ToolContext($this->user));

        expect($result->error)->toBe('invalid arguments');
    });
});
