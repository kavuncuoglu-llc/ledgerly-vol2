<?php

namespace App\Ai\Cache;

use App\Ai\Embeddings\Embedder;
use App\Ai\Schemas\Category;
use App\Casts\Vector;
use App\Models\CachedAnswer;
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

    public function store(string $question, Category $category, string $answer, ?int $tenantId): ?CachedAnswer
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
            ['answer' => $answer, 'embedding' => $vector, 'status' => 'auto', 'expires_at' => $this->policy->expiresAt()],
        );
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
