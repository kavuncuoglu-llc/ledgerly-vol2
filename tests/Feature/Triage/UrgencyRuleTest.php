<?php

use App\Ai\Schemas\Category;
use App\Ai\Schemas\TicketData;
use App\Ai\Triage\Assignment;
use App\Ai\Triage\Urgency;
use App\Ai\Triage\UrgencyRule;
use App\Models\Customer;
use App\Models\Invoice;

function fields(?string $invoice = null): TicketData
{
    return new TicketData(Category::Billing, $invoice, null, null, null);
}

it('is urgent when the customer says so', function (string $text) {
    expect(UrgencyRule::evaluate(Category::HowTo, null, Customer::factory()->make(['plan' => 'starter']), $text))->toBe(Urgency::Urgent);
})->with([
    'This is urgent, please help',
    'I am locked out of my account',
    'We were charged twice for the same invoice',
    'I need this fixed ASAP',
]);

it('is urgent for a bug on an enterprise account, normal on any other', function () {
    expect(UrgencyRule::evaluate(Category::Bug, null, Customer::factory()->make(['plan' => 'enterprise']), 'The PDF button does nothing'))->toBe(Urgency::Urgent)
        ->and(UrgencyRule::evaluate(Category::Bug, null, Customer::factory()->make(['plan' => 'pro']), 'The PDF button does nothing'))->toBe(Urgency::Normal);
});

it('is urgent for a billing ticket about an invoice that is 30 or more days overdue, counted from the working date', function () {
    $customer = Customer::factory()->create();

    Invoice::factory()->for($customer)->overdue(41)->create(['number' => 'INV-4438']);
    Invoice::factory()->for($customer)->overdue(18)->create(['number' => 'INV-4471']);

    expect(UrgencyRule::evaluate(Category::Billing, fields('4438'), $customer))->toBe(Urgency::Urgent)
        ->and(UrgencyRule::reason(Category::Billing, fields('4438'), $customer))->toBe('the invoice is 30 or more days overdue')
        ->and(UrgencyRule::evaluate(Category::Billing, fields('4471'), $customer))->toBe(Urgency::Normal)
        ->and(UrgencyRule::evaluate(Category::Billing, fields('9999'), $customer))->toBe(Urgency::Normal);
});

it('does not look at another customer\'s invoice to decide', function () {
    Invoice::factory()->for(Customer::factory()->create())->overdue(90)->create(['number' => 'INV-7777']);

    expect(UrgencyRule::evaluate(Category::Billing, fields('7777'), Customer::factory()->create()))->toBe(Urgency::Normal);
});

it('assigns a team by category, and sends an urgent bug to the on-call engineers', function () {
    expect(Assignment::for(Category::Billing, Urgency::Normal))->toBe('billing-team')
        ->and(Assignment::for(Category::HowTo, Urgency::Normal))->toBe('docs-team')
        ->and(Assignment::for(Category::Account, Urgency::Urgent))->toBe('account-team')
        ->and(Assignment::for(Category::Bug, Urgency::Normal))->toBe('engineering')
        ->and(Assignment::for(Category::Bug, Urgency::Urgent))->toBe('engineering-oncall')
        ->and(Assignment::for(Category::Other, Urgency::Normal))->toBe('support-queue');
});
