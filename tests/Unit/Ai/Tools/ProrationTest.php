<?php

use App\Ai\Tools\CalculateProration\CalculateProration;
use App\Ai\Tools\CalculateProration\CalculateProrationArgs;
use App\Ai\Tools\CalculateProration\Proration;
use App\Ai\Tools\ToolKind;
use Illuminate\Support\Facades\Validator;

// Chapter 38, Topic 8: the arithmetic bucket goes to a function. The examples are the help center's own.
it('prorates a plan change as the help center does: halfway from Starter to Pro is $15', function () {
    $result = Proration::planChange(oldPrice: 19.0, newPrice: 49.0, daysRemaining: 15, daysInPeriod: 30);

    expect($result['amount'])->toBe(15.0)
        ->and($result['formula'])->toBe('(49.00 - 19.00) x 15 / 30');
});

it('charges nothing for a plan change on the last day of a period of zero days remaining', function () {
    expect(Proration::planChange(19.0, 49.0, 0, 30)['amount'])->toBe(0.0);
});

it('rounds a plan change to whole cents', function () {
    expect(Proration::planChange(19.0, 49.0, 10, 31)['amount'])->toBe(9.68);
});

it('computes the late fee of the help center example: $1,000 at day 30 is $15, at day 40 is $20', function () {
    expect(Proration::lateFee(1000.0, 30)['amount'])->toBe(15.0)
        ->and(Proration::lateFee(1000.0, 40)['amount'])->toBe(20.0);
});

it('charges no late fee under 1.00 and says it was the minimum that stopped it', function () {
    $result = Proration::lateFee(66.0, 30);

    expect($result['amount'])->toBe(0.0)
        ->and($result['below_minimum'])->toBeTrue()
        ->and(Proration::lateFee(66.67, 30)['amount'])->toBe(1.0);
});

it('reads and writes nothing, so it is a read', function () {
    expect((new CalculateProration)->kind())->toBe(ToolKind::Read);
});

it('needs the numbers of the kind it was asked for', function () {
    $rules = CalculateProrationArgs::rules();

    $base = ['kind' => 'late_fee', 'old_price' => null, 'new_price' => null, 'days_remaining' => null, 'days_in_period' => null, 'balance' => null, 'days_overdue' => null];

    expect(Validator::make($base, $rules)->fails())->toBeTrue()
        ->and(Validator::make([...$base, 'balance' => 1000, 'days_overdue' => 30], $rules)->passes())->toBeTrue()
        ->and(Validator::make([...$base, 'kind' => 'plan_change'], $rules)->fails())->toBeTrue()
        ->and(Validator::make([...$base, 'kind' => 'plan_change', 'old_price' => 19, 'new_price' => 49, 'days_remaining' => 31, 'days_in_period' => 30], $rules)->fails())->toBeTrue()
        ->and(Validator::make([...$base, 'kind' => 'refund', 'balance' => 1, 'days_overdue' => 1], $rules)->fails())->toBeTrue();
});
