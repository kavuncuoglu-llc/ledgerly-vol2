<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Chapter 37, Topic 7: one batch of requests for work nobody is waiting on.
class AiBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'completed_at' => 'datetime', 'collected_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AiBatchItem::class, 'batch_id');
    }
}
