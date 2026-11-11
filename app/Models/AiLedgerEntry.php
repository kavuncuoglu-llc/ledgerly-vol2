<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Chapter 36, Figure 36-2: one append-only row per model call, in integer micro-dollars. Numbers and labels only, so it can be kept for years and read by finance.
class AiLedgerEntry extends Model
{
    public $timestamps = false;

    protected $table = 'ai_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micro_usd' => 'integer',
        ];
    }

    public function usd(): float
    {
        return $this->cost_micro_usd / 1_000_000;
    }
}
