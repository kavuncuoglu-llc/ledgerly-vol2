<?php

use App\Ai\Cache\CachePolicy;
use App\Ai\Cache\HelpVersion;
use App\Ai\Schemas\Category;

beforeEach(function () {
    $this->policy = new CachePolicy;
});

it('is on or off by config', function () {
    config(['ai.semantic_cache.enabled' => true]);

    expect($this->policy->enabled())->toBeTrue();

    config(['ai.semantic_cache.enabled' => false]);

    expect($this->policy->enabled())->toBeFalse();
});

it('caches how-to questions and nothing else by default', function () {
    expect($this->policy->cacheable(Category::HowTo))->toBeTrue()
        ->and($this->policy->cacheable(Category::Billing))->toBeFalse()
        ->and($this->policy->cacheable(Category::Account))->toBeFalse()
        ->and($this->policy->cacheable(Category::Bug))->toBeFalse()
        ->and($this->policy->cacheable(Category::Other))->toBeFalse();
});

it('ignores a configured label the classifier does not have', function () {
    config(['ai.semantic_cache.categories' => ['howto', 'policy']]);

    expect($this->policy->categories())->toBe([Category::HowTo]);
});

it('uses 0.92 unless a category has its own threshold', function () {
    expect($this->policy->threshold(Category::HowTo))->toBe(0.92);

    config(['ai.semantic_cache.thresholds' => ['howto' => 0.95]]);

    expect($this->policy->threshold(Category::HowTo))->toBe(0.95)
        ->and($this->policy->threshold(Category::Billing))->toBe(0.92);
});

describe('which questions can stand alone', function () {
    it('lets a question about the product through', function (string $question) {
        expect($this->policy->isGeneric($question))->toBeTrue();
    })->with([
        'How do I set up recurring invoices?',
        'Can I make an invoice repeat every month?',
        'What is a late fee?',
        'How do I invite a team member?',
    ]);

    it('keeps a question about this customer\'s account out', function (string $question) {
        expect($this->policy->isGeneric($question))->toBeFalse();
    })->with([
        'Why is my March invoice late?',
        'What is the status of our retainer?',
        'Why is INV-4471 marked overdue?',
        'What happened to invoice 4471?',
        'Look at #4471 please',
        'What is T-10422 about?',
        'Was I charged on 2026-03-03?',
        'Did the 4/20 payment arrive?',
        'What was charged yesterday?',
        'Send it to maya@acme-studios.example.com',
        'How do I do that this month?',
    ]);

    it('keeps a very long question out: it is a story, not a question', function () {
        expect($this->policy->isGeneric(str_repeat('How do I set up recurring invoices? ', 12)))->toBeFalse();
    });
});

describe('which answers are worth keeping', function () {
    it('keeps a plain answer when grounding is not required', function () {
        config(['ai.semantic_cache.require_context' => false]);

        expect($this->policy->worthStoring('Open the invoice, choose Make recurring, and set the interval.', grounded: false))->toBeTrue();
    });

    it('never keeps an escape-hatch answer', function (string $answer) {
        expect($this->policy->worthStoring($answer, grounded: true))->toBeFalse();
    })->with([
        'That depends on your account, but I can open a support ticket for you.',
        'I can\'t help with that here.',
        'That is outside what I can help with.',
        'I don\'t have that information.',
        'The answer is not in the provided context.',
        '',
        '   ',
    ]);

    it('wants grounding by default from Chapter 15 on', function () {
        expect(config('ai.semantic_cache.require_context'))->toBeTrue()
            ->and($this->policy->worthStoring('Open the invoice.', grounded: false))->toBeFalse()
            ->and($this->policy->worthStoring('Open the invoice.', grounded: true))->toBeTrue();
    });
});

it('expires a stored answer after the configured days, or never', function () {
    config(['ai.semantic_cache.ttl_days' => 30]);

    expect($this->policy->expiresAt()->isFuture())->toBeTrue()
        ->and((int) round(now()->diffInDays($this->policy->expiresAt())))->toBe(30);

    config(['ai.semantic_cache.ttl_days' => null]);

    expect($this->policy->expiresAt())->toBeNull();
});

describe('HelpVersion', function () {
    it('is the configured version when there is one, cut to 40 characters', function () {
        config(['ledgerly.help_version' => str_repeat('a', 60)]);

        expect((new HelpVersion)->current())->toBe(str_repeat('a', 40));
    });

    it('is a hash of the help articles otherwise, and changes when an article does', function () {
        config(['ledgerly.help_version' => null]);

        helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "Body.\n\n## One\n\nText.\n")]);

        $first = (new HelpVersion)->current();

        helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "Body.\n\n## One\n\nChanged text.\n")]);

        $second = (new HelpVersion)->current();

        helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing', "Body.\n\n## One\n\nChanged text.\n")]);

        expect($first)->toHaveLength(12)
            ->and($second)->not->toBe($first)
            ->and((new HelpVersion)->current())->toBe($second);
    });

    it('works out the hash once per instance', function () {
        config(['ledgerly.help_version' => null]);

        helpLibrary(['billing/late-fees.md' => helpArticleSource('Late fees', 'billing')]);

        $version = new HelpVersion;
        $first = $version->current();

        helpLibrary(['billing/other.md' => helpArticleSource('Other', 'billing')]);

        expect($version->current())->toBe($first);

        $version->forget();

        expect($version->current())->not->toBe($first);
    });
});
