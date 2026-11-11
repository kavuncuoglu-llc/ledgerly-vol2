<?php

namespace App\Models;

use App\Ai\Tools\PendingAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingToolCall extends Model
{
    protected $table = 'pending_tool_actions';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $pending) {
            $pending->args_hash = $pending->args_hash ?: PendingAction::hashOf($pending->arguments ?? []);        // every row is bound to its arguments, whoever created it

            $pending->expires_at ??= now()->addMinutes(PendingAction::TTL_MINUTES);
        });
    }

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'messages' => 'array',
            'options' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
