<?php

use App\Ai\Cache\PrefixCacheEstimator;
use App\Ai\Context\ContextAssembler;
use App\Ai\Context\ProviderProfile;
use App\Ai\Security\Untrusted;
use App\Ai\Tokens\RatioCounter;
use Illuminate\Support\Facades\Log;

function assembler(bool $breakpoints = false): ContextAssembler
{
    return new ContextAssembler(new RatioCounter, new ProviderProfile('ollama', $breakpoints));
}

function history(int $exchanges, int $from = 1): array
{
    $messages = [];

    foreach (range($from, $from + $exchanges - 1) as $n) {
        $messages[] = ['role' => 'user', 'content' => "question {$n} ".str_repeat('x', 100)];
        $messages[] = ['role' => 'assistant', 'content' => "answer {$n} ".str_repeat('y', 100)];
    }

    return $messages;
}

it('builds in the stable-first order whatever order the slots were filled in', function () {
    $prompt = assembler()
        ->question('The question')
        ->context([retrievedChunk('Fees start after 30 days.')])
        ->history(history(1))
        ->summary('Problem: late fee.')
        ->system('You are the support assistant.')
        ->build();

    expect(array_column($prompt->messages, 'role'))->toBe(['system', 'system', 'user', 'assistant', 'user', 'user'])
        ->and($prompt->messages[0]['content'])->toBe('You are the support assistant.')
        ->and($prompt->messages[1]['content'])->toStartWith('Earlier in this conversation:')
        ->and($prompt->messages[2]['content'])->toStartWith('<untrusted-b1f3ec6d source="customer_message">'."\nquestion 1")
        ->and($prompt->messages[4]['content'])->toContain("[late-fees › How the fee is calculated]\n".Untrusted::wrap('Fees start after 30 days.', 'help_center'))
        ->and($prompt->messages[5]['content'])->toBe("Question:\n".Untrusted::wrap('The question', 'customer_message'));
});

it('keeps the order in one constant so Exercise 1 is a one-line change', function () {
    expect(ContextAssembler::ORDER)->toBe(['system', 'summary', 'history', 'context', 'question']);
});

it('leaves out an empty summary and an empty history, and says when there is no context', function () {
    $prompt = assembler()->system('S')->summary(null)->history([])->context([])->question('Q')->build();

    expect($prompt->messages)->toBe([
        ['role' => 'system', 'content' => 'S'],
        ['role' => 'user', 'content' => "Context:\n(no context provided)"],
        ['role' => 'user', 'content' => "Question:\n".Untrusted::wrap('Q', 'customer_message')],
    ]);
});

it('counts tokens per slot', function () {
    $prompt = assembler()
        ->system(str_repeat('s', 360))
        ->summary(str_repeat('m', 100))
        ->history(history(2))
        ->context([])
        ->question(str_repeat('q', 36))
        ->build();

    expect($prompt->breakdown['system'])->toBe(100)
        ->and($prompt->breakdown['summary'])->toBe((new RatioCounter)->count("Earlier in this conversation:\n".str_repeat('m', 100)))
        ->and($prompt->breakdown['history'])->toBe(2 * (new RatioCounter)->count(Untrusted::wrap('question 1 '.str_repeat('x', 100), 'customer_message')) + 2 * (new RatioCounter)->count('answer 1 '.str_repeat('y', 100)))
        ->and($prompt->breakdown['question'])->toBe((new RatioCounter)->count("Question:\n".Untrusted::wrap(str_repeat('q', 36), 'customer_message')))
        ->and(array_keys($prompt->breakdown))->toBe(['system', 'summary', 'history', 'context', 'question'])
        ->and($prompt->tokens())->toBe(array_sum($prompt->breakdown));
});

it('reports the memories as their own slot while sending one system message', function () {
    $prompt = assembler()->system(str_repeat('s', 360), "What you know about this customer:\n- role: bookkeeper")->question('Q')->build();

    expect($prompt->messages[0]['content'])->toBe(str_repeat('s', 360)."\n\nWhat you know about this customer:\n- role: bookkeeper")
        ->and($prompt->breakdown['system'])->toBe(100)
        ->and($prompt->breakdown['memories'])->toBe((new RatioCounter)->count("What you know about this customer:\n- role: bookkeeper"))
        ->and(array_slice(array_keys($prompt->breakdown), 0, 2))->toBe(['system', 'memories']);
});

it('counts the tool calls in the history as tokens', function () {
    $calls = [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_invoices', 'arguments' => '{"status":null,"months":6}']]];

    $without = assembler()->system('S')->history([['role' => 'assistant', 'content' => null]])->question('Q')->build();
    $with = assembler()->system('S')->history([['role' => 'assistant', 'content' => null, 'tool_calls' => $calls]])->question('Q')->build();

    expect($with->breakdown['history'])->toBeGreaterThan($without->breakdown['history']);
});

describe('cache breakpoints', function () {
    it('marks the end of the system message and of the summary when the provider wants breakpoints', function () {
        $prompt = assembler(breakpoints: true)->system('S')->summary('Problem: late fee.')->history(history(1))->question('Q')->build();

        $marked = collect($prompt->messages)->map(fn (array $m) => $m['cache_control'] ?? null)->all();

        expect($marked)->toBe([['type' => 'ephemeral'], ['type' => 'ephemeral'], null, null, null]);
    });

    it('marks only the system message when there is no summary', function () {
        $prompt = assembler(breakpoints: true)->system('S')->history(history(1))->question('Q')->build();

        expect(collect($prompt->messages)->map(fn (array $m) => isset($m['cache_control']))->all())->toBe([true, false, false, false]);
    });

    it('emits none when the provider caches by prefix on its own', function () {
        $prompt = assembler(breakpoints: false)->system('S')->summary('x')->history(history(1))->question('Q')->build();

        expect(collect($prompt->messages)->contains(fn (array $m) => isset($m['cache_control'])))->toBeFalse();
    });

    it('decides from the provider unless config forces it', function () {
        expect((new ProviderProfile('anthropic'))->supportsCacheBreakpoints())->toBeTrue()
            ->and((new ProviderProfile('ollama'))->supportsCacheBreakpoints())->toBeFalse()
            ->and((new ProviderProfile('openai'))->supportsCacheBreakpoints())->toBeFalse()
            ->and((new ProviderProfile('ollama', true))->supportsCacheBreakpoints())->toBeTrue()
            ->and((new ProviderProfile('anthropic', false))->supportsCacheBreakpoints())->toBeFalse();

        config(['ai.provider' => 'anthropic', 'ai.cache_breakpoints' => null]);

        expect(ProviderProfile::fromConfig()->supportsCacheBreakpoints())->toBeTrue();

        config(['ai.cache_breakpoints' => '']);

        expect(ProviderProfile::fromConfig()->supportsCacheBreakpoints())->toBeTrue();

        config(['ai.cache_breakpoints' => 'false']);

        expect(ProviderProfile::fromConfig()->supportsCacheBreakpoints())->toBeFalse();
    });
});

it('warns about a slot over its budget and still sends it', function () {
    Log::spy();

    config(['ai.slots' => ['system' => 10]]);

    $prompt = assembler()->system(str_repeat('s', 360))->question('Q')->build();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Context slot over budget' && $context['slot'] === 'system' && $context['budget'] === 10)->once();

    expect($prompt->messages[0]['content'])->toHaveLength(360);
});

it('is a new object for every prompt: slots never leak from one turn to the next', function () {
    $first = app(ContextAssembler::class)->system('S')->summary('old summary')->question('Q1')->build();
    $second = app(ContextAssembler::class)->system('S')->question('Q2')->build();

    expect($first->messages)->toHaveCount(3)
        ->and($second->messages)->toHaveCount(2);
});

describe('what the order does to the cache (Exercise 1)', function () {
    function sharedTokens(array $previous, array $current): int
    {
        $estimator = new PrefixCacheEstimator(new RatioCounter);

        $a = json_encode([[], $previous], JSON_UNESCAPED_SLASHES);
        $b = json_encode([[], $current], JSON_UNESCAPED_SLASHES);

        return (new RatioCounter)->count(substr($b, 0, $estimator->sharedPrefixLength($a, $b)));
    }

    function growingTurn(bool $contextFirst, int $n): array
    {
        $system = ['role' => 'system', 'content' => str_repeat('s', 1200)];
        $history = history($n);                                              // append-only: the window has not had to drop anything yet
        $context = ['role' => 'user', 'content' => "Context:\nPassage retrieved for turn {$n}. ".str_repeat('p', 300)];
        $question = ['role' => 'user', 'content' => "Question: turn {$n}"];

        return $contextFirst
            ? [$system, $context, ...$history, $question]
            : [$system, ...$history, $context, $question];
    }

    it('caches the whole history when the per-turn context comes after it', function () {
        $shared = sharedTokens(growingTurn(false, 7), growingTurn(false, 8));
        $total = (new RatioCounter)->count(json_encode(growingTurn(false, 8), JSON_UNESCAPED_SLASHES));

        expect($shared / $total)->toBeGreaterThan(0.7);
    });

    it('caches only the system message when the context comes first: the same tokens at several times the price', function () {
        $good = sharedTokens(growingTurn(false, 7), growingTurn(false, 8));
        $bad = sharedTokens(growingTurn(true, 7), growingTurn(true, 8));
        $system = (new RatioCounter)->count(json_encode(['role' => 'system', 'content' => str_repeat('s', 1200)]));

        expect($bad)->toBeLessThanOrEqual($system + 20)
            ->and($good)->toBeGreaterThan($bad * 2);
    });

    it('is the order the assembler builds, whatever order the caller fills the slots in', function () {
        $prompt = assembler()->system('S')->context([retrievedChunk('c')])->history(history(1))->question('Q')->build();

        expect(array_column($prompt->messages, 'content')[3])->toStartWith("The following passages are from Ledgerly's help center");
    });

    it('does not cache a window that slides: dropping the front exchange moves everything behind it', function () {
        $system = ['role' => 'system', 'content' => str_repeat('s', 1200)];

        $previous = [$system, ...history(6, 1)];
        $slid = [$system, ...history(6, 2)];

        $systemTokens = (new RatioCounter)->count(json_encode($system));

        expect(sharedTokens($previous, $slid))->toBeLessThanOrEqual($systemTokens + 30);
    });
});
