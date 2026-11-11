<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingToolCall extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'messages' => 'array',
            'options' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
