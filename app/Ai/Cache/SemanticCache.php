<?php

namespace App\Ai\Cache;

use App\Ai\Embeddings\Embedder;
use App\Ai\Schemas\Category;
use App\Casts\Vector;
use App\Models\CachedAnswer;
use App\Models\HelpChunk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SemanticCache
{
    private ?string $lastQuestion = null;

    private ?array $lastVector = null;

    public function __construct(private Embedder $embedder, private CachePolicy $policy, private HelpVersion $helpVersion) {}

    public function lookup(string $question, Category $category, ?int $tenantId): ?CachedAnswer
    {
        if (! $this->policy->cacheable($category)) {
            return null;
        }

        return $this->nearest($question, [$category], $tenantId);
    }

    /** The cache is asked before the classifier: a hit costs one embedding, not a second model call. The row remembers the category it was stored under. */
    public function find(string $question, ?int $tenantId): ?CachedAnswer
    {
        $categories = $this->policy->categories();

        if ($categories === []) {
            return null;
        }

        return $this->nearest($question, $categories, $tenantId);
    }

    /** @param  array<int, array{id: int, version: string}>|null  $cited  the help-center chunks the answer cited, with the version of each (Chapter 37) */
    public function store(string $question, Category $category, string $answer, ?int $tenantId, ?array $cited = null): ?CachedAnswer
    {
        if (! $this->policy->cacheable($category)) {
            return null;
        }

        $vector = $this->embed($question);

        if ($vector === null) {
            return null;
        }

        $scopedTo = $this->policy->tenantScoped($category)
            ? $tenantId
            : null;

        $existing = CachedAnswer::query()
            ->where('question', $question)
            ->where('category', $category->value)
            ->where('help_version', $this->helpVersion->current())
            ->where('tenant_id', $scopedTo)
            ->first();

        if ($existing?->status === 'approved') {
            return $existing;                                          // a human reviewed that one: a fresh model answer does not replace it
        }

        return CachedAnswer::updateOrCreate(
            ['question' => $question, 'category' => $category->value, 'help_version' => $this->helpVersion->current(), 'tenant_id' => $scopedTo],
            ['answer' => $answer, 'embedding' => $vector, 'status' => 'auto', 'expires_at' => $this->policy->expiresAt(), 'cited_chunks' => $cited],
        );
    }

    // Chapter 37, Topic 5: freshness by the sources an answer cited. When a chunk's text changes every answer that cited the old text is forgotten at once, so a customer never gets an answer built on text that no longer exists;
    // the listing filters by the chunk's tenant, but a shared chunk is cited by answers stored for any tenant, so this does not. Scheduled expiry (ai.semantic_cache.ttl_days) remains the backstop for anything this misses.
    public function forgetCiting(HelpChunk $chunk): int
    {
        $stale = CachedAnswer::query()
            ->whereJsonContains('cited_chunks', [['id' => $chunk->id]])
            ->get(['id', 'cited_chunks'])
            ->filter(fn (CachedAnswer $answer) => (collect($answer->cited_chunks)->firstWhere('id', $chunk->id)['version'] ?? null) !== $chunk->version())
            ->pluck('id');

        return CachedAnswer::query()->whereIn('id', $stale)->delete();
    }

    // A chunk that no longer exists: every answer that cited it goes.
    public function forgetChunk(int $chunkId): int
    {
        return CachedAnswer::query()->whereJsonContains('cited_chunks', [['id' => $chunkId]])->delete();
    }

    public function recordHit(CachedAnswer $hit): void
    {
        CachedAnswer::query()->whereKey($hit->id)->increment('hit_count');
    }

    /** @param Category[] $categories */
    private function nearest(string $question, array $categories, ?int $tenantId): ?CachedAnswer
    {
        $vector = $this->embed($question);

        if ($vector === null) {
            return null;
        }

        $literal = Vector::literal($vector);

        return CachedAnswer::query()
            ->select(['id', 'tenant_id', 'category', 'question', 'answer', 'help_version', 'status', 'hit_count', 'expires_at', 'created_at', 'updated_at'])   // not the 768 floats
            ->selectRaw('1 - (embedding <=> ?::vector) as similarity', [$literal])
            ->where(function (Builder $q) use ($categories, $literal) {
                foreach ($categories as $category) {
                    $q->orWhere(fn (Builder $inner) => $inner
                        ->where('category', $category->value)
                        ->whereRaw('1 - (embedding <=> ?::vector) >= ?', [$literal, $this->policy->threshold($category)]));
                }
            })
            ->where(fn (Builder $q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->where('help_version', $this->helpVersion->current())
            ->whereIn('status', ['auto', 'approved'])
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->first();
    }

    private function embed(string $question): ?array
    {
        if ($question === $this->lastQuestion) {
            return $this->lastVector;
        }

        try {
            $vector = $this->embedder->query($question);
        } catch (Throwable $e) {
            Log::warning('Semantic cache skipped: the question could not be embedded', ['error' => $e->getMessage()]);   // a cache that fails is a cache that misses

            return null;
        }

        $this->lastQuestion = $question;
        $this->lastVector = $vector;

        return $vector;
    }
}
