<?php

use App\Ai\Prompts\SupportPrompt;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Streaming\StreamListener;
use App\Ai\Support\SupportAssistant;
use App\Models\AiLedgerEntry;
use App\Models\ModelCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

// Chapter 37, Topic 3 and Figure 37-9: start cheap, check, escalate on evidence with the same sources.
const CITED = 'A late fee of 1.5% applies after the grace period. [late-fees › How the fee is calculated]';
const UNCITED = 'A late fee of 1.5% applies after the grace period.';
const DECLINED = 'I can\'t find that in the Ledgerly help center, and I won\'t guess.';

beforeEach(function () {
    spendControlsOn();

    config([
        'ai.budgets.enabled' => false,
        'ai.cascade.enabled' => true,
        'ai-routes' => ['default' => ['default' => ['start' => 'local', 'chain' => ['local', 'frontier'], 'buffers' => true]]],
    ]);

    [$this->user] = customerWithInvoice();

    $this->retrievals = retrieverReturning([retrievedChunk('A late fee of 1.5% applies after the grace period.')]);

    Context::flush();
});

function retrieverReturning(array $chunks): object
{
    $retriever = new class($chunks) implements Retriever
    {
        public int $calls = 0;

        public function __construct(private array $chunks) {}

        public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
        {
            $this->calls++;

            return $this->chunks;
        }
    };

    app()->instance(Retriever::class, $retriever);

    return $retriever;
}

// The local role and the hosted role are two servers: each gets its own scripted answers, and the order they were called in says who answered.
function servers(array $local, array $frontier = ['unused']): void
{
    $script = function (array $texts) {
        $next = 0;

        return function (Request $request) use (&$next, $texts) {
            $text = $texts[min($next++, count($texts) - 1)];

            return ($request['stream'] ?? false)
                ? Http::response(streamBody(mb_str_split($text, 24)), 200, ['Content-Type' => 'text/event-stream'])      // asked to stream: a stream, in a few pieces
                : Http::response(chatBody($text));
        };
    };

    Http::fake(['localhost:11434/*' => $script($local), 'frontier.example.com/*' => $script($frontier)]);
}

function providerOrder(): array
{
    return Http::recorded()->map(fn (array $pair) => str_contains($pair[0]->url(), 'frontier.example.com') ? 'frontier' : 'local')->values()->all();
}

function askCascaded($user, ?array &$deltas = null)
{
    $deltas = [];

    $stream = new StreamListener(function (string $delta) use (&$deltas) {
        $deltas[] = $delta;
    });

    return app(SupportAssistant::class)->answer($user, 'How are late fees calculated?', stream: $stream);
}

it('settles on the cheap role when its answer passes, and the dearer role is never called', function () {
    servers([CITED]);

    $result = askCascaded($this->user);

    expect($result->answer())->toBe(CITED)
        ->and(providerOrder())->toBe(['local'])
        ->and(ModelCall::where('feature', 'support.reply')->where('cascade_step', 0)->count())->toBe(1);
});

it('holds the cheap answer back until it passes, then sends it at once', function () {
    servers([CITED]);

    askCascaded($this->user, $deltas);

    expect($deltas)->toBe([CITED]);                                                  // one piece, after the checks, not word by word
});

it('escalates a failed citation check to the next role with the same sources, and does not retrieve twice', function () {
    servers([UNCITED, UNCITED], [CITED]);                                            // the cheap role, its one repair, then the dearer role

    $result = askCascaded($this->user);

    expect($result->answer())->toBe(CITED)
        ->and(providerOrder())->toBe(['local', 'local', 'frontier'])
        ->and($this->retrievals->calls)->toBe(1);

    $steps = ModelCall::where('feature', 'support.reply')->orderBy('id')->get(['provider', 'cascade_step', 'cascade_failed']);

    expect($steps->pluck('cascade_step')->all())->toBe([0, 0, 1])
        ->and($steps->pluck('cascade_failed')->all())->toBe(['citations', 'citations', null])
        ->and($steps->pluck('provider')->unique()->values()->all())->toBe(['local', 'frontier']);
});

it('counts a model that declines a question its sources answer as a failure', function () {
    servers([DECLINED], [CITED]);

    $result = askCascaded($this->user);

    expect($result->answer())->toBe(CITED)
        ->and(ModelCall::where('cascade_step', 0)->first()->cascade_failed)->toBe('wrongful_decline');
});

it('does not count a decline when the sources are far from the question, and starts a tier up instead', function () {
    retrieverReturning([retrievedChunk(overrides: ['similarity' => 0.30])]);

    servers(['unused'], [DECLINED]);

    askCascaded($this->user);

    expect(providerOrder())->toBe(['frontier']);                                     // best passage under skip_cheapest_below: the cheap attempt is skipped; a decline is then honest
});

it('hands over to a person when every role fails, never a failed draft', function () {
    servers([UNCITED], [UNCITED]);

    $result = askCascaded($this->user);

    expect($result->answer())->toBe(SupportPrompt::escapeHatch())
        ->and(ModelCall::where('cascade_step', 1)->first()->cascade_failed)->toBe('citations');
});

it('streams the last role live', function () {
    config(['ai-routes' => ['default' => ['default' => ['start' => 'frontier', 'chain' => ['frontier'], 'buffers' => false]]]]);

    Http::fake(['frontier.example.com/*' => streamReply(['A late fee ', 'of 1.5% applies. ', '[late-fees › How the fee is calculated]'])]);

    askCascaded($this->user, $deltas);

    expect(count($deltas))->toBeGreaterThan(1)
        ->and(implode('', $deltas))->toContain('1.5%');
});

it('leaves a role that is not set up out of the chain', function () {
    config(['ai.providers.frontier.api_key' => '']);

    servers([UNCITED]);

    $result = askCascaded($this->user);

    expect(providerOrder())->toBe(['local', 'local'])                                // the cheap role and its one repair; the hosted role is not tried
        ->and($result->answer())->toBe(SupportPrompt::escapeHatch());
});

it('answers as it always has when the cascade is off', function () {
    config(['ai.cascade.enabled' => false]);

    servers([CITED]);

    askCascaded($this->user);

    expect(ModelCall::whereNotNull('cascade_id')->count())->toBe(0);
});

it('answers as it always has when the table has no row for the category', function () {
    config(['ai-routes' => ['billing' => ['default' => ['start' => 'frontier', 'chain' => ['frontier']]]]]);

    servers([CITED]);

    askCascaded($this->user);

    expect(providerOrder())->toBe(['local'])->and(ModelCall::whereNotNull('cascade_id')->count())->toBe(0);
});

it('records the cascade run and the step on every call of an attempt', function () {
    servers([CITED]);

    askCascaded($this->user);

    $call = ModelCall::where('feature', 'support.reply')->first();

    expect($call->cascade_id)->toMatch('/^[0-9a-f-]{36}$/')->and($call->cascade_step)->toBe(0);
});

it('puts the step on the ledger row too', function () {
    config(['ai.ledger.enabled' => true]);

    fakeStreams();

    servers([UNCITED, UNCITED], [CITED]);

    askCascaded($this->user);

    $this->artisan('ai:ledger-consume', ['--once' => true])->assertSuccessful();

    expect(AiLedgerEntry::where('feature', 'support.reply')->orderBy('id')->pluck('cascade_step')->all())->toBe([0, 0, 1]);
});

it('uses a feature\'s own max_tokens, not the generous default', function () {
    config(['ai.features' => array_replace(config('ai.features'), ['support.reply' => config('ai.features')['support.reply'] + ['max_tokens' => 180]])]);

    servers([CITED]);

    askCascaded($this->user);

    Http::assertSent(fn ($request) => ($request['max_tokens'] ?? null) === 180);
});
