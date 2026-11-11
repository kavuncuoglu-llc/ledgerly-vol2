<?php

use App\Ai\Cache\SemanticCache;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Schemas\Category;
use App\Models\CachedAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Support\Facades\Http;

// Chapter 34, Topic 7: ask another tenant's exact question, and assert that nothing of theirs comes back from retrieval, the cache or memory. The book's HybridRetriever is PgRetriever behind the Retriever interface here.
beforeEach(function () {
    config(['ledgerly.help_version' => 'v1', 'ai.retrieval.threshold' => 0.5, 'ai.semantic_cache.enabled' => true, 'ai.semantic_cache.require_context' => false, 'ai.semantic_cache.categories' => ['howto', 'billing'], 'ai.semantic_cache.tenant_categories' => ['billing']]);

    $this->question = 'Why is invoice #4471 late?';

    Http::fake(['*/embeddings' => fakeEmbeddingAngles([$this->question => 0], otherwise: 0.0)]);

    [$this->acme, $this->globex] = [Tenant::factory()->create(['name' => 'Acme']), Tenant::factory()->create(['name' => 'Globex'])];

    $this->acmeUser = User::factory()->inTenant($this->acme)->create();

    $this->globexUser = User::factory()->inTenant($this->globex)->create();

    $this->acmeChunk = chunkRow(['tenant_id' => $this->acme->id, 'source' => 'help/private/acme.md', 'heading' => 'Acme billing', 'content' => 'Why is invoice #4471 late? Acme Studios pays every vendor on the fifteenth.', 'embedding' => angledVector(0)]);

    $this->acmeAnswer = CachedAnswer::create([
        'tenant_id' => $this->acme->id,
        'category' => 'billing',
        'question' => $this->question,
        'answer' => 'Invoice #4471 is late because Acme Studios pays on the fifteenth.',
        'help_version' => 'v1',
        'status' => 'auto',
        'embedding' => angledVector(0),
    ]);

    UserMemory::withoutGlobalScopes()->create(['user_id' => $this->acmeUser->id, 'tenant_id' => $this->acme->id, 'key' => 'billing_contact', 'value' => 'dana@acme.example', 'source' => 'stated']);
})->group('privacy');

it('has something of Acme\'s in every store, which Acme itself can see (the control)', function () {
    $retrieved = app(Retriever::class)->retrieve($this->question, new RetrievalScope(tenantId: $this->acme->id));

    expect(collect($retrieved)->pluck('id')->all())->toContain($this->acmeChunk->id)
        ->and(collect($retrieved)->pluck('tenantId')->all())->toContain($this->acme->id)
        ->and(app(SemanticCache::class)->find($this->question, $this->acme->id)?->id)->toBe($this->acmeAnswer->id);

    actingAsTenant($this->acme);

    expect(UserMemory::pluck('value')->all())->toBe(['dana@acme.example']);
});

it('returns nothing of tenant A to tenant B, from any similarity store', function () {
    $retrieved = app(Retriever::class)->retrieve($this->question, new RetrievalScope(tenantId: $this->globex->id));

    expect(collect($retrieved)->pluck('tenantId')->unique()->all())->not->toContain($this->acme->id)
        ->and(collect($retrieved)->pluck('id')->all())->not->toContain($this->acmeChunk->id)
        ->and(app(SemanticCache::class)->find($this->question, $this->globex->id))->toBeNull()
        ->and(app(SemanticCache::class)->lookup($this->question, Category::Billing, $this->globex->id))->toBeNull();

    actingAsTenant($this->globex);

    expect(UserMemory::pluck('tenant_id')->all())->not->toContain($this->acme->id)
        ->and(UserMemory::count())->toBe(0);
});

it('keeps the shared scope away from a tenant\'s private passage too', function () {
    $retrieved = app(Retriever::class)->retrieve($this->question, RetrievalScope::shared());

    expect(collect($retrieved)->pluck('id')->all())->not->toContain($this->acmeChunk->id);
});

it('does not let a tenant\'s cached answer become everybody\'s: a billing answer is stored under its tenant', function () {
    $stored = app(SemanticCache::class)->store('How do I pay a late invoice?', Category::Billing, 'Pay it from the invoice page.', $this->acme->id);

    expect($stored?->tenant_id)->toBe($this->acme->id);

    expect(app(SemanticCache::class)->find('How do I pay a late invoice?', $this->globex->id))->toBeNull();
});
