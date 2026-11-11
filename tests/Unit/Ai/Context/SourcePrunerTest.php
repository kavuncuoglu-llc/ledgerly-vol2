<?php

use App\Ai\Context\ContextAssembler;
use App\Ai\Context\ProviderProfile;
use App\Ai\Context\SourcePruner;
use App\Ai\Retrieval\RetrievedChunk;
use App\Ai\Tokens\RatioCounter;

// Chapter 37, Topic 6: deduplication and trimming of retrieved sources.
function prunerChunk(string $body, string $heading = 'Late fees › A', array $overrides = []): RetrievedChunk
{
    return retrievedChunk($body, 'help/billing/late-fees.md', $heading, $overrides + ['id' => crc32($heading.$body) % 100000, 'chunkKey' => md5($heading.$body)]);
}

function pruner(): SourcePruner
{
    return new SourcePruner(new RatioCounter);
}

it('drops a child whose text its parent already carries, whichever came first', function () {
    $parent = prunerChunk("The fee is 1.5% of the outstanding balance per month, prorated by day.\n\nA fee under one dollar is not charged.", 'Late fees › Parent');

    $child = prunerChunk('The fee is 1.5% of the outstanding balance per month, prorated by day.', 'Late fees › Child');

    expect(pruner()->prune([$child, $parent]))->toBe([$parent])
        ->and(pruner()->prune([$parent, $child]))->toBe([$parent]);
});

it('drops a chunk that says nearly the same as a better-ranked one, and keeps the better-ranked', function () {
    $steps = 'Open the Invoices page, choose the invoices you want, then press Export and pick CSV or PDF from the menu. The file is prepared in the background and a link to it is emailed to you when it is ready, usually within a minute, and it stays available for seven days after that.';

    $first = prunerChunk($steps.' Large exports may take longer.', 'Export › One');

    $second = prunerChunk($steps.' Very large exports may take much longer.', 'Export › Two');

    expect(pruner()->prune([$first, $second]))->toBe([$first]);
});

it('keeps chunks that differ in the detail that matters', function () {
    $a = prunerChunk('The late fee is 1.5% of the outstanding balance per month and is charged after the thirty day grace period ends.', 'Late fees › A');

    $b = prunerChunk('A late fee is never charged on an invoice that is under dispute, whatever its age, until the dispute is resolved.', 'Late fees › B');

    expect(pruner()->prune([$a, $b]))->toBe([$a, $b]);
});

it('can be switched off', function () {
    config()->set('ai.pruning.dedupe', false);

    $a = prunerChunk('The same text repeated here for the test of switching it off completely.', 'A');

    $b = prunerChunk('The same text repeated here for the test of switching it off completely.', 'B');

    expect(pruner()->prune([$a, $b]))->toBe([$a, $b]);
});

describe('trimming', function () {
    function longChunk(): RetrievedChunk
    {
        $filler = fn (string $topic) => "The {$topic} settings page lists every option in alphabetical order with a short note beside each one. ".str_repeat("More general notes about {$topic} fill this paragraph to a sensible length. ", 12);

        return prunerChunk($filler('colour')."\n\n".$filler('language')."\n\nTo waive a late fee, open the invoice, choose Late fee, and press Waive; the fee is removed and the customer is not told.\n\n".$filler('shortcut'), 'Late fees › Long');
    }

    it('is off until the golden set says it is safe', function () {
        $chunk = longChunk();

        expect(pruner()->prune([$chunk], 'How do I waive a late fee?'))->toBe([$chunk]);
    });

    it('keeps the paragraph that matches the question and drops the filler, under the limit', function () {
        config()->set('ai.pruning.trim', true);

        $chunk = longChunk();

        [$trimmed] = pruner()->prune([$chunk], 'How do I waive a late fee?');

        expect($trimmed->body())->toContain('press Waive')
            ->and($trimmed->body())->not->toContain('colour settings page')
            ->and((new RatioCounter)->count($trimmed->body()))->toBeLessThan((new RatioCounter)->count($chunk->body()) / 2)
            ->and($trimmed->label())->toBe($chunk->label())                              // a citation to it still checks
            ->and($trimmed->id)->toBe($chunk->id);
    });

    it('leaves a short chunk, and a chunk with one paragraph, alone', function () {
        config()->set('ai.pruning.trim', true);

        $short = prunerChunk('A short passage.', 'Short');

        $one = prunerChunk(str_repeat('One long paragraph with no break in it at all. ', 60), 'One');

        expect(pruner()->prune([$short, $one], 'any question about this'))->toBe([$short, $one]);
    });

    it('works in the assembler, and the hard budget still caps what is left', function () {
        config()->set('ai.pruning.trim', true);

        $assembler = new ContextAssembler(new RatioCounter, new ProviderProfile('local'));

        $prompt = $assembler->system('Be brief.')->context([longChunk()], 'How do I waive a late fee?')->question('How do I waive a late fee?')->build();

        expect($prompt->breakdown['context'])->toBeLessThan(600);
    });
});
