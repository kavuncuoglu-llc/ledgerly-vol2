<?php

use App\Ai\Context\ContextAssembler;
use App\Ai\Context\ProviderProfile;
use App\Ai\Context\SourceBudget;
use App\Ai\Retrieval\RetrievedChunk;
use App\Ai\Tokens\RatioCounter;

// Chapter 35, Topic 4: a budget no setting elsewhere can exceed.
function chunkOfTokens(int $tokens, string $source = 'help/billing/late-fees.md', string $heading = 'Late fees › A'): RetrievedChunk
{
    return retrievedChunk(str_repeat('a', (int) round($tokens * 3.6)), $source, $heading);       // RatioCounter: 3.6 characters to a token
}

it('adds sources best first until the budget is spent', function () {
    $counter = new RatioCounter;

    $chunks = [chunkOfTokens(1_000), chunkOfTokens(1_000, heading: 'Late fees › B'), chunkOfTokens(1_000, heading: 'Late fees › C'), chunkOfTokens(1_000, heading: 'Late fees › D')];

    $selection = (new SourceBudget($counter))->fit($chunks, 2_500);

    expect(count($selection->kept))->toBe(2)
        ->and($selection->kept[0])->toBe($chunks[0])
        ->and($selection->kept[1])->toBe($chunks[1])
        ->and(count($selection->dropped))->toBe(2)
        ->and($selection->tokens)->toBeLessThanOrEqual(2_500);
});

it('skips a chunk that does not fit and keeps looking: a smaller one later may still fit', function () {
    $counter = new RatioCounter;

    $small = chunkOfTokens(100, heading: 'Late fees › Small');

    $chunks = [chunkOfTokens(900), chunkOfTokens(900, heading: 'Late fees › B'), $small];

    $selection = (new SourceBudget($counter))->fit($chunks, 1_100);

    expect($selection->kept)->toBe([$chunks[0], $small])
        ->and($selection->dropped)->toBe([$chunks[1]]);
});

it('cannot be raised by a bigger top-k or by longer chunks', function () {
    config()->set('ai.context.sources_max_tokens', 3_000);

    $chunks = array_map(fn (int $i) => chunkOfTokens(5_000, heading: "Late fees › {$i}"), range(1, 10));

    $prompt = (new ContextAssembler(new RatioCounter, new ProviderProfile('local')))->system('Be brief.')->context($chunks)->question('How do I reset my password?')->build();

    expect($prompt->breakdown['context'])->toBeLessThan(3_100)
        ->and($prompt->labelMap)->toBe([]);                          // ten chunks of 5,000 tokens: none fits, and the prompt says so rather than carrying them
});

it('reads its default from config', function () {
    config()->set('ai.context.sources_max_tokens', 500);

    $selection = (new SourceBudget(new RatioCounter))->fit([chunkOfTokens(400), chunkOfTokens(400, heading: 'Late fees › B')]);

    expect(count($selection->kept))->toBe(1)
        ->and($selection->budget)->toBe(500);
});
