<?php

use App\Ai\Policy\GoodwillCreditPolicy;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolGate;
use App\Ai\Tools\ToolRegistry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Chapter 51, Topic 3: credits are decided by rules; the model explains the answer.
function creditFor(Customer $customer, int $lateFeeCents = 2160, string $number = 'INV-7001'): Invoice
{
    return Invoice::factory()->for($customer)->overdue(18)->withLateFee($lateFeeCents)->create(['number' => $number]);
}

function giveCredit(Customer $customer, Invoice $invoice, string $on = 'now'): void
{
    DB::table('invoice_credits')->insert(['invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'amount_cents' => 500, 'reason' => 'earlier', 'created_at' => $on === 'now' ? now() : now()->modify($on), 'updated_at' => now()]);
}

describe('the policy', function () {
    beforeEach(function () {
        $this->customer = Customer::factory()->create();

        $this->policy = new GoodwillCreditPolicy;
    });

    it('allows a credit up to the late fee', function () {
        $decision = $this->policy->decide(creditFor($this->customer), $this->customer->id);

        expect($decision->eligible)->toBeTrue()
            ->and($decision->maxCents)->toBe(2160)
            ->and($decision->code)->toBe('late_fee_within_limit')
            ->and($decision->allows(2160))->toBeTrue()
            ->and($decision->allows(2161))->toBeFalse()
            ->and($decision->allows(0))->toBeFalse()
            ->and($decision->explanation)->toContain('$21.60');
    });

    it('caps a large late fee at the configured limit', function () {
        config(['ai.goodwill.max_cents' => 1500]);

        expect($this->policy->decide(creditFor($this->customer, 9000), $this->customer->id)->maxCents)->toBe(1500);
    });

    it('refuses an invoice with no late fee', function () {
        $decision = $this->policy->decide(creditFor($this->customer, 0), $this->customer->id);

        expect($decision->eligible)->toBeFalse()
            ->and($decision->code)->toBe('no_late_fee')
            ->and($decision->maxCents)->toBe(0);
    });

    it('refuses a second credit in the window and allows one after it', function () {
        $invoice = creditFor($this->customer);

        giveCredit($this->customer, $invoice);

        expect($this->policy->decide($invoice, $this->customer->id)->code)->toBe('recent_credit');

        DB::table('invoice_credits')->update(['created_at' => now()->subDays(91)]);

        expect($this->policy->decide($invoice, $this->customer->id)->eligible)->toBeTrue();
    });

    it('says there is no such invoice when there is none', function () {
        expect($this->policy->decide(null, $this->customer->id)->code)->toBe('invoice_not_found');
    });

    it('gives two accounts that differ only in name, country, currency and plan the same decision', function () {
        $a = Customer::factory()->create(['name' => 'Okonkwo & Daughters', 'country' => 'US', 'currency' => 'USD', 'plan' => 'starter']);

        $b = Customer::factory()->create(['name' => 'Lindqvist Byggservice AB', 'country' => 'DE', 'currency' => 'EUR', 'plan' => 'enterprise']);

        $first = $this->policy->decide(creditFor($a), $a->id)->toArray();

        $second = $this->policy->decide(creditFor($b, number: 'INV-7002'), $b->id)->toArray();

        expect($first)->toBe($second);
    });
});

describe('the tools', function () {
    beforeEach(function () {
        $this->customer = Customer::factory()->create();

        $this->user = User::factory()->owner($this->customer)->create();

        $this->invoice = creditFor($this->customer);

        $this->gate = app(ToolGate::class);
    });

    it('check_goodwill_credit returns the decision, for the customer\'s own invoice only', function () {
        $tool = app(ToolRegistry::class)->native()->get('check_goodwill_credit');

        $own = $this->gate->dispatch($tool, ['invoice_number' => '7001'], new ToolContext($this->user))->toArray();

        expect($own)->toMatchArray(['ok' => true, 'eligible' => true, 'max_cents' => 2160, 'rule' => 'late_fee_within_limit']);

        $stranger = User::factory()->owner(Customer::factory()->create())->create();

        $other = $this->gate->dispatch($tool, ['invoice_number' => '7001'], new ToolContext($stranger))->toArray();

        expect($other)->toMatchArray(['ok' => true, 'eligible' => false, 'rule' => 'invoice_not_found']);
    });

    it('check_goodwill_credit is a read tool with no side effects', function () {
        $tool = app(ToolRegistry::class)->native()->get('check_goodwill_credit');

        $before = DB::table('invoice_credits')->count();

        $this->gate->dispatch($tool, ['invoice_number' => '7001'], new ToolContext($this->user));

        expect($tool->risk()->name)->toBe('Read')
            ->and(DB::table('invoice_credits')->count())->toBe($before);
    });

    it('issue_credit asks nobody to approve what the policy refuses', function () {
        $result = $this->gate->dispatch(app(ToolRegistry::class)->get('issue_credit'), ['invoice_number' => '7001', 'amount_cents' => 5000, 'reason' => 'I would like more'], new ToolContext($this->user));

        expect($result->ok)->toBeFalse()
            ->and($result->pending)->toBeNull()
            ->and($result->error)->toContain('allows at most 2160 cents');
    });

    it('issue_credit still asks a person to approve what the policy allows', function () {
        $result = $this->gate->dispatch(app(ToolRegistry::class)->get('issue_credit'), ['invoice_number' => '7001', 'amount_cents' => 1800, 'reason' => 'Late fee while a payment was in transit'], new ToolContext($this->user));

        expect($result->isPending())->toBeTrue();
    });

    it('an approval does not widen the policy: the tool asks it again when it writes', function () {
        $tool = app(ToolRegistry::class)->get('issue_credit');

        giveCredit($this->customer, $this->invoice);

        $args = (object) ['invoice_number' => '7001', 'amount_cents' => 1800, 'reason' => 'Late fee while a payment was in transit'];

        $result = DB::transaction(fn () => $tool->handle($args, new ToolContext($this->user, approvedBy: 1)));

        expect($result->error)->toBe('not allowed by the goodwill credit policy')
            ->and(DB::table('invoice_credits')->count())->toBe(1);
    });
});
