<?php

namespace App\Models;

use App\Ai\Cache\SemanticCache;
use App\Casts\Vector;
use Illuminate\Database\Eloquent\Model;

class HelpChunk extends Model
{
    protected $guarded = [];

    // Chapter 37, Topic 5: the version of one chunk is a hash of its text. A cached answer remembers the version of every chunk it cited; when a chunk's text changes, the answers that cited it are forgotten (SemanticCache::forgetCiting).
    public function version(): string
    {
        return substr(sha1((string) $this->content), 0, 12);
    }

    protected static function booted(): void
    {
        static::updated(fn (self $chunk) => app(SemanticCache::class)->forgetCiting($chunk));

        static::deleted(fn (self $chunk) => app(SemanticCache::class)->forgetChunk($chunk->id));
    }

    protected $casts = [
        'position' => 'integer',
        'tokens' => 'integer',
        'tags' => 'array',
        'source_modified_at' => 'datetime',
        'embedding' => Vector::class,
    ];
}
