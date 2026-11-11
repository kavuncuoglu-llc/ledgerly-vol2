<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelCall extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
            'cost_micros' => 'integer',
            'conversation_id' => 'integer',
            'cached_tokens' => 'integer',
            'ttft_ms' => 'integer',
            'context_breakdown' => 'array',
            'semantic_cache_hit' => 'boolean',
            'request' => 'array',
        ];
    }
}
