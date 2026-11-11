<?php

namespace App\Models;

use App\Ai\Retrieval\RetrievedChunk;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'tokens' => 'integer',
            'tool_calls' => 'array',
            'retrieved' => 'array',
            'guard' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function retrievedLabels(): array
    {
        return collect($this->retrieved ?? [])->pluck('eval_label')->all();
    }

    // The passages the live reply was shown, rebuilt from the chunk ids stored with it, so a candidate is compared on the same sources (Chapter 30, Topic 4).
    public function retrievedSources(): array
    {
        $ids = collect($this->retrieved ?? [])->pluck('id');

        $chunks = HelpChunk::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $ids
            ->filter(fn ($id) => $chunks->has($id))
            ->map(fn ($id) => RetrievedChunk::fromModel($chunks[$id]))
            ->values()
            ->all();
    }

    // The turns before the question this reply answers: the conversation as the live model saw it, minus tool traffic.
    public function historyBefore(int $limit = 6): array
    {
        $questionSeq = (int) static::withoutGlobalScopes()
            ->where('conversation_id', $this->conversation_id)
            ->where('role', 'user')
            ->where('content', $this->question)
            ->where('seq', '<', $this->seq)
            ->max('seq');

        return static::withoutGlobalScopes()
            ->where('conversation_id', $this->conversation_id)
            ->where('seq', '<', $questionSeq)
            ->whereIn('role', ['user', 'assistant'])
            ->whereNotNull('content')
            ->where('status', 'completed')
            ->orderByDesc('seq')
            ->limit($limit)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (self $message) => ['role' => $message->role, 'content' => $message->content])
            ->values()
            ->all();
    }

    public function isCompacted(): bool
    {
        return $this->role === 'tool'
            && str_starts_with((string) $this->content, '{"compacted":true');
    }
}
