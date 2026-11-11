<?php

use App\Ai\Prompts\SupportPrompt;
use App\Models\User;
use Illuminate\Support\Carbon;

function promptFor(User $user, string $question, string $at): string
{
    Carbon::setTestNow($at);

    return SupportPrompt::assemble(question: $question, user: $user)->prefixBeforeCacheBreakpoint();
}

it('keeps the cacheable prefix identical across customers and times', function () {
    [$acme] = customerWithInvoice('INV-4471');
    [$globex] = customerWithInvoice('INV-4472');

    $a = promptFor($acme, 'How do I export my invoices?', '2026-11-03 09:00');
    $b = promptFor($globex, 'Why was I charged a late fee?', '2026-11-04 17:45');

    expect($a)->toBe($b)
        ->and($a)->not->toBe('[]');

    Carbon::setTestNow();
});

it('holds no date, time or name in the bytes before the breakpoint', function () {
    [$acme] = customerWithInvoice('INV-4471');

    $prefix = promptFor($acme, 'hello', '2026-11-03 14:32');

    expect($prefix)->not->toContain('2026')
        ->and($prefix)->not->toContain('14:32')
        ->and($prefix)->not->toContain($acme->name);

    Carbon::setTestNow();
});

it('fails when a line that varies is put at the top of the system message (Exercise 3)', function () {
    [$acme] = customerWithInvoice('INV-4471');

    $withDate = fn (string $at) => (function () use ($acme, $at) {
        Carbon::setTestNow($at);

        return SupportPrompt::assemble(question: 'hello', user: $acme, system: 'Today is '.now()->toDayDateTimeString().'. '.SupportPrompt::base())->prefixBeforeCacheBreakpoint();
    })();

    expect($withDate('2026-11-03 14:32'))->not->toBe($withDate('2026-11-03 14:33'));

    Carbon::setTestNow();
});

it('puts the varying parts after the breakpoint', function () {
    [$acme] = customerWithInvoice('INV-4471');

    $prompt = SupportPrompt::assemble(question: 'How do I export my invoices?', user: $acme);

    $after = collect($prompt->messages)->slice($prompt->prefixMessages)->pluck('content')->implode("\n");

    expect($prompt->prefixMessages)->toBe(1)
        ->and($after)->toContain('How do I export my invoices?');
});
