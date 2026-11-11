<?php

use App\Evals\Injection\InjectionFixture;
use App\Evals\Injection\InjectionSet;

// Chapter 32, Figure 32-9 and Appendix I: the fixtures are data, so they are checked like data.
it('ships fixtures for every carrier and every goal, with unique ids', function () {
    $fixtures = InjectionSet::load('tests/Fixtures/injection');

    expect($fixtures->count())->toBeGreaterThanOrEqual(30)
        ->and($fixtures->pluck('carrier')->unique()->sort()->values()->all())->toBe(collect(InjectionFixture::CARRIERS)->sort()->values()->all())
        ->and($fixtures->pluck('goal')->unique()->sort()->values()->all())->toBe(collect(InjectionFixture::GOALS)->sort()->values()->all())
        ->and($fixtures->pluck('id')->duplicates())->toBeEmpty();
})->group('security');

it('plants every payload where its carrier says, and names how to tell it worked', function () {
    foreach (InjectionSet::load('tests/Fixtures/injection') as $fixture) {
        $planted = match ($fixture->carrier) {
            'invoice_line_item' => isset($fixture->plant['invoice'], $fixture->plant['line_description']),
            'tool_result' => isset($fixture->plant['invoice'], $fixture->plant['payment_reference']),
            'help_chunk' => isset($fixture->plant['text']),
            'ticket_body' => isset($fixture->plant['body']),
            'customer_message' => $fixture->plant === [] && strlen($fixture->question) > 10,
        };

        expect($planted)->toBeTrue("{$fixture->id} is not planted where its carrier says")
            ->and($fixture->modelSuccessIf)->not->toBeEmpty("{$fixture->id} has no model-level check");

        if ($fixture->goal !== 'change_language') {
            expect($fixture->systemSuccessIf)->not->toBeEmpty("{$fixture->id} has no system-level check");
        }
    }
})->group('security');

it('keeps the smoke subset the same between runs', function () {
    $all = InjectionSet::load('tests/Fixtures/injection');

    $first = InjectionSet::smoke($all, 2)->pluck('id')->all();

    expect($first)->toBe(InjectionSet::smoke($all->shuffle(), 2)->pluck('id')->all())
        ->and(count($first))->toBe(10);
})->group('security');

it('rejects a fixture with an unknown carrier', function () {
    InjectionFixture::fromArray(['id' => 'x', 'carrier' => 'pigeon', 'customer' => 'acme', 'question' => 'q', 'goal' => 'reveal_prompt', 'system_success_if' => [], 'model_success_if' => []]);
})->throws(InvalidArgumentException::class, "unknown carrier 'pigeon'")->group('security');
