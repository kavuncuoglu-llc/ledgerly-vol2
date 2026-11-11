<?php

use App\Ai\AiManager;
use App\Ai\Prompts\RewritePrompt;
use App\Ai\Retrieval\IdentifierPattern;
use App\Ai\Retrieval\QueryRewriter;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Retrieval\RetrieverFactory;
use App\Ai\Retrieval\RewritingRetriever;
use App\Ai\Tokens\TokenCounter;
use App\Models\ModelCall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function rewriter(string $mode = 'rewrite'): QueryRewriter
{
    return new QueryRewriter(app(AiManager::class), $mode);
}

function chatCount(): int
{
    return count(sentChatBodies());
}

beforeEach(function () {
    Sleep::fake();
});

describe('QueryRewriter', function () {
    it('restates a question in documentation register with one deterministic, short call', function () {
        fakeChatSequence(chatBody('automatic reminders and late fees for overdue customer invoices'));

        $variants = rewriter()->variants('my client keeps paying me late, what can I do');

        $body = sentChatBodies()[0];

        expect($variants)->toBe(['automatic reminders and late fees for overdue customer invoices'])
            ->and($body['messages'][0]['content'])->toBe(RewritePrompt::system('rewrite'))
            ->and($body['messages'][1])->toBe(['role' => 'user', 'content' => 'my client keeps paying me late, what can I do'])
            ->and($body['temperature'])->toBe(0)
            ->and($body['max_tokens'])->toBe(60);
    });

    it('logs the call as retrieval.rewrite with its prompt version', function () {
        fakeChatSequence(chatBody('late fees'));

        rewriter()->variants('what if my client is late');

        $call = ModelCall::sole();

        expect($call->feature)->toBe('retrieval.rewrite')
            ->and($call->prompt_version)->toBe(RewritePrompt::VERSION);
    });

    it('expands to at most three phrasings, one per line, without bullets or numbers', function () {
        fakeChatSequence(chatBody("1. set up payment reminders for late-paying clients\n- charge a late fee on overdue invoices\n\"options when a customer pays invoices late\"\nextra fourth line"));

        expect(rewriter('expand')->variants('my client keeps paying me late'))->toBe([
            'set up payment reminders for late-paying clients',
            'charge a late fee on overdue invoices',
            'options when a customer pays invoices late',
        ]);
    });

    it('writes one hypothetical answer paragraph for hyde', function () {
        fakeChatSequence(chatBody("You can enable automatic payment reminders.\nThey email the client at set intervals."));

        expect(rewriter('hyde')->variants('my client keeps paying me late'))->toBe(['You can enable automatic payment reminders. They email the client at set intervals.'])
            ->and(sentChatBodies()[0]['max_tokens'])->toBe(150);
    });

    it('does nothing when it is off or the mode is unknown', function () {
        Http::fake();

        expect(rewriter('off')->variants('anything at all'))->toBe([])
            ->and(rewriter('nonsense')->variants('anything at all'))->toBe([]);

        Http::assertNothingSent();
    });

    it('skips a question that carries an identifier, so the keyword side sees it verbatim', function (string $question) {
        Http::fake();

        expect(rewriter()->variants($question))->toBe([]);

        Http::assertNothingSent();
    })->with([
        'status of invoice 4471',
        'what happened to INV-4471',
        'why is #4471 late',
        'update on T-10422',
        'email maya@acme-studios.example.com about it',
    ]);

    it('recognises identifiers but not ordinary numbers', function () {
        expect(IdentifierPattern::matches('status of invoice 4471'))->toBeTrue()
            ->and(IdentifierPattern::matches('can I add 2 team members'))->toBeFalse();
    });

    it('fails soft: no variants, and retrieval goes on with the original question', function () {
        Http::fake(['*/chat/completions' => Http::response('down', 500)]);

        expect(rewriter()->variants('my client keeps paying me late'))->toBe([]);
    });

    it('does not repeat the original as a variant', function () {
        fakeChatSequence(chatBody('My client keeps paying me late'));

        expect(rewriter()->variants('my client keeps paying me late'))->toBe([]);
    });

    it('remembers a rewrite so the same question costs one call', function () {
        fakeChatSequence(chatBody('late fees'), chatBody('something else'));

        rewriter()->variants('what if my client is late');
        rewriter()->variants('what if my client is late');

        expect(chatCount())->toBe(1);
    });

    it('has a pinned prompt text', function () {
        expect(md5(RewritePrompt::system('rewrite')))->toBe('d5a9a5cfd6ef624fea4a67e140d6a6b1')            // bump VERSION and the hashes together
            ->and(md5(RewritePrompt::system('expand')))->toBe('e822b47b30c9be33c24bf74078b1d3cc')
            ->and(md5(RewritePrompt::system('hyde')))->toBe('9c06b58639f306fbe747e5ed9cb56e1d')
            ->and(app(TokenCounter::class)->count(RewritePrompt::system('expand')))->toBeLessThan(100)
            ->and(RewritePrompt::system('rewrite'))->toContain('documentation register')->toContain('Keep every identifier')
            ->and(fn () => RewritePrompt::system('nonsense'))->toThrow(InvalidArgumentException::class);
    });
});

describe('RewritingRetriever', function () {
    function scripted(array $byQuestion): Retriever
    {
        return new class($byQuestion) implements Retriever
        {
            public array $asked = [];

            public function __construct(private array $byQuestion) {}

            public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
            {
                $this->asked[] = $question;

                return array_map(fn (int $id) => retrievedChunk("body {$id}", overrides: ['id' => $id]), array_slice($this->byQuestion[$question] ?? [], 0, $k));
            }
        };
    }

    it('retrieves on the original and the rewrite and merges the lists with reciprocal rank fusion', function () {
        fakeChatSequence(chatBody('automatic reminders and late fees'));

        $inner = scripted([
            'my client keeps paying me late' => [10, 11, 12],
            'automatic reminders and late fees' => [20, 11, 21],
        ]);

        $result = (new RewritingRetriever($inner, rewriter()))->retrieve('my client keeps paying me late', RetrievalScope::shared(), 4);

        expect($inner->asked)->toBe(['my client keeps paying me late', 'automatic reminders and late fees'])
            ->and(array_map(fn ($c) => $c->id, $result))->toBe([11, 10, 20, 12]);       // 11 is in both lists; ties keep first-seen order
    });

    it('is a plain pass-through when there is nothing to rewrite', function () {
        Http::fake();

        $inner = scripted(['status of invoice 4471' => [1, 2]]);

        $result = (new RewritingRetriever($inner, rewriter()))->retrieve('status of invoice 4471', RetrievalScope::shared(), 5);

        expect($inner->asked)->toBe(['status of invoice 4471'])
            ->and(array_map(fn ($c) => $c->id, $result))->toBe([1, 2]);

        Http::assertNothingSent();
    });

    it('merges three expansions', function () {
        fakeChatSequence(chatBody("a\nb\nc"));

        $inner = scripted(['q' => [1], 'a' => [2], 'b' => [2, 3], 'c' => [4]]);

        $result = (new RewritingRetriever($inner, rewriter('expand')))->retrieve('q', RetrievalScope::shared(), 10);

        expect($inner->asked)->toBe(['q', 'a', 'b', 'c'])
            ->and(array_map(fn ($c) => $c->id, $result)[0])->toBe(2);
    });
});

describe('the wired retriever', function () {
    it('is wrapped in a rewriter when ai.retrieval.rewrite is on, and not when it is off', function () {
        config(['ai.retrieval.reranker' => 'none', 'ai.retrieval.rewrite' => 'rewrite']);

        expect(app(Retriever::class))->toBeInstanceOf(RewritingRetriever::class);

        config(['ai.retrieval.rewrite' => 'off']);

        expect(app(Retriever::class))->not->toBeInstanceOf(RewritingRetriever::class)
            ->and(app(RetrieverFactory::class)->make(['rewrite' => 'expand']))->toBeInstanceOf(RewritingRetriever::class);
    });

    it('widens the candidate pool the reranker sees, with the rewrite as one more list', function () {
        fakeEmbeddings();

        config(['ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.0, 'ai.retrieval.rewrite' => 'rewrite', 'ai.retrieval.reranker' => 'none']);

        chunkRow(['source' => 'help/reminders/schedule.md', 'heading' => 'Reminders › Automatic reminder schedule', 'content' => 'Automatic reminders for overdue invoices go out at three, seven and fourteen days.']);
        chunkRow(['source' => 'help/billing/refunds.md', 'heading' => 'Refunds › Issuing a refund', 'content' => 'Open the payment and choose refund to return money to a card.']);

        Http::fake([
            '*/embeddings' => fn ($request) => embeddingsResponse($request),
            '*/chat/completions' => Http::response(chatBody('automatic reminders for overdue invoices')),
        ]);

        $result = app(Retriever::class)->retrieve('my client keeps paying me late', RetrievalScope::shared(), 1);

        expect($result[0]->source)->toBe('help/reminders/schedule.md');
    });
});
