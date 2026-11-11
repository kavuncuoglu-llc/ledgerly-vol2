<?php

use App\Ai\Tokens\RatioCounter;
use App\Ai\Tokens\TiktokenCounter;
use App\Ai\Tokens\TokenCounter;

it('estimates tokens from the characters per token ratio', function () {
    $counter = new RatioCounter(4.0);

    expect($counter->count('Invoice #4471 is overdue by 30 days.'))->toBe(9)
        ->and($counter->count(''))->toBe(0);
});

it('rounds up so the estimate never undercounts', function () {
    expect((new RatioCounter(4.0))->count('abcde'))->toBe(2);
});

it('counts characters rather than bytes', function () {
    expect((new RatioCounter(1.0))->count('日本語'))->toBe(3);
});

it('uses 3.6 characters per token by default', function () {
    expect((new RatioCounter)->count(str_repeat('a', 36)))->toBe(10);
});

it('binds the ratio counter by default', function () {
    config(['ai.token_counter' => 'ratio']);
    app()->forgetInstance(TokenCounter::class);

    expect(app(TokenCounter::class))->toBeInstanceOf(RatioCounter::class);
});

it('binds the tiktoken counter when configured', function () {
    config(['ai.token_counter' => 'tiktoken']);
    app()->forgetInstance(TokenCounter::class);

    expect(app(TokenCounter::class))->toBeInstanceOf(TiktokenCounter::class);
});
