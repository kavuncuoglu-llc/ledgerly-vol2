<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TriageRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'votes' => 'array',
            'fields' => 'array',
            'findings' => 'array',
            'agreement' => 'float',
            'seconds' => 'float',
            'draft_citations_ok' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function isHandled(): bool
    {
        return in_array($this->status, ['automated', 'human'], true);
    }
}
