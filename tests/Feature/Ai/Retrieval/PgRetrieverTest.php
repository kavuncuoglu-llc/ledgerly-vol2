<?php

use App\Ai\Retrieval\PgRetriever;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeEmbeddings();

    config(['ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.5]);

    $this->feeWhen = chunkRow(['content' => 'A late fee is applied automatically thirty days after the due date unless the invoice is disputed.', 'heading' => 'Late fees › When a late fee is applied']);
    $this->recurring = chunkRow(['source' => 'help/recurring/setup.md', 'heading' => 'Recurring invoices › Setting up', 'content' => 'Pick monthly and the same invoice is created again every month on the schedule you choose.', 'tags' => ['area' => 'recurring']]);
    $this->status = chunkRow(['source' => 'help/invoices/statuses.md', 'heading' => 'Invoice statuses › Overview', 'content' => 'Invoice INV-4471 style numbers appear on every invoice. Statuses are Draft, Sent, Overdue, Paid and Void.', 'tags' => ['area' => 'invoices']]);

    $this->retriever = app(Retriever::class);
});

it('is the retriever the container hands out', function () {
    expect($this->retriever)->toBeInstanceOf(PgRetriever::class);
});

it('returns the best passage first, with a similarity, a label and a link', function () {
    $chunks = $this->retriever->retrieve('when is a late fee applied after the due date', RetrievalScope::shared());

    expect($chunks)->not->toBeEmpty()
        ->and($chunks[0]->id)->toBe($this->feeWhen->id)
        ->and($chunks[0]->similarity)->toBeGreaterThan(0.5)
        ->and($chunks[0]->label())->toBe('late-fees › When a late fee is applied')
        ->and($chunks[0]->url())->toBe('/help/billing/late-fees#when-a-late-fee-is-applied');
});

it('returns an empty list when nothing is close enough', function () {
    config(['ai.retrieval.threshold' => 0.7]);

    app()->forgetInstance(PgRetriever::class);

    $chunks = app(Retriever::class)->retrieve('best pizza near me', RetrievalScope::shared());

    expect($chunks)->toBe([]);
});

it('keeps a keyword hit that the vector side scored below the floor', function () {
    config(['ai.retrieval.threshold' => 0.99]);

    app()->forgetInstance(PgRetriever::class);

    $chunks = app(Retriever::class)->retrieve('Overdue Void', RetrievalScope::shared());

    expect(collect($chunks)->pluck('id')->all())->toBe([$this->status->id])
        ->and($chunks[0]->keywordRank)->toBeGreaterThan(0.0);
});

it('can run vector only, which misses a rare word, and keyword only, which finds it', function () {
    $vectorOnly = $this->retriever->with(['mode' => 'vector', 'threshold' => 0.99]);
    $keywordOnly = $this->retriever->with(['mode' => 'keyword']);

    expect($vectorOnly->retrieve('Overdue Void', RetrievalScope::shared()))->toBe([])
        ->and(collect($keywordOnly->retrieve('Overdue Void', RetrievalScope::shared()))->pluck('id')->all())->toBe([$this->status->id]);
});

it('refuses an unknown mode', function () {
    $this->retriever->with(['mode' => 'telepathy'])->retrieve('anything at all', RetrievalScope::shared());
})->throws(InvalidArgumentException::class, 'Unknown retrieval mode');

it('searches only the current help_version', function () {
    chunkRow(['help_version' => 'v0', 'content' => 'A late fee is applied automatically thirty days after the due date unless the invoice is disputed (old text).']);

    $chunks = $this->retriever->retrieve('when is a late fee applied after the due date', RetrievalScope::shared());

    expect(collect($chunks)->pluck('helpVersion')->unique()->all())->toBe(['v1']);
});

it('skips the embedding call entirely when nothing is ingested for this version', function () {
    config(['ledgerly.help_version' => 'never-ingested']);

    Http::fake();

    expect($this->retriever->retrieve('when is a late fee applied', RetrievalScope::shared()))->toBe([]);

    Http::assertNothingSent();
});

it('pre-filters by area', function () {
    $chunks = $this->retriever->retrieve('every month on the schedule you choose', new RetrievalScope(area: 'invoices'));

    expect(collect($chunks)->pluck('id')->all())->not->toContain($this->recurring->id);
});

it('pre-filters by plan: a starter customer never sees an enterprise-only passage', function () {
    $enterprise = chunkRow(['source' => 'help/billing/custom-terms.md', 'heading' => 'Custom terms', 'content' => 'Enterprise plans negotiate custom late fee terms and net payment periods.', 'tags' => ['area' => 'billing', 'plans' => ['enterprise']]]);

    $asStarter = $this->retriever->retrieve('custom late fee terms for enterprise', new RetrievalScope(plan: 'starter'));
    $asEnterprise = $this->retriever->retrieve('custom late fee terms for enterprise', new RetrievalScope(plan: 'enterprise'));
    $asUnknown = $this->retriever->retrieve('custom late fee terms for enterprise', RetrievalScope::shared());

    expect(collect($asStarter)->pluck('id')->all())->not->toContain($enterprise->id)
        ->and(collect($asUnknown)->pluck('id')->all())->not->toContain($enterprise->id)
        ->and(collect($asEnterprise)->pluck('id')->all())->toContain($enterprise->id)
        ->and(collect($asEnterprise)->firstWhere('id', $enterprise->id)->isShared())->toBeFalse();
});

it('keeps one tenant\'s private passage away from every other tenant and from the shared scope', function () {
    $acme = Tenant::factory()->create();
    $other = Tenant::factory()->create();

    $private = chunkRow(['tenant_id' => $acme->id, 'source' => 'help/private/acme.md', 'heading' => 'Acme billing rules', 'content' => 'Acme Studios pays every vendor invoice on the fifteenth, never earlier.']);

    $question = 'when does Acme Studios pay vendor invoices';

    $asAcme = $this->retriever->retrieve($question, new RetrievalScope(tenantId: $acme->id));
    $asOther = $this->retriever->retrieve($question, new RetrievalScope(tenantId: $other->id));
    $asShared = $this->retriever->retrieve($question, RetrievalScope::shared());

    expect(collect($asAcme)->pluck('id')->all())->toContain($private->id)
        ->and(collect($asOther)->pluck('id')->all())->not->toContain($private->id)
        ->and(collect($asShared)->pluck('id')->all())->not->toContain($private->id);
});

it('hits the halfvec hnsw index when the planner is not forced off a sequential scan', function () {
    DB::statement('SET LOCAL enable_seqscan = off');

    $plan = collect(DB::select('EXPLAIN SELECT id FROM help_chunks ORDER BY embedding::halfvec(768) <=> ?::halfvec(768) LIMIT 5', ['['.implode(',', array_fill(0, 768, 0.1)).']']))
        ->map(fn ($row) => $row->{'QUERY PLAN'})
        ->implode("\n");

    expect($plan)->toContain('help_chunks_embedding_hnsw');
});

it('merges the two lists by reciprocal rank fusion: a chunk found by both sides outranks a chunk found by one', function () {
    $chunks = $this->retriever->with(['threshold' => 0.0])->retrieve('late fee applied due date disputed', RetrievalScope::shared());

    expect($chunks[0]->id)->toBe($this->feeWhen->id)
        ->and($chunks[0]->keywordRank)->not->toBeNull()
        ->and($chunks[0]->score)->toBeGreaterThan(1 / 61.0 + 1 / 62.0 - 0.0001)
        ->and($chunks[0]->score)->toBeLessThanOrEqual(2 / 61.0 + 0.0001);
});
