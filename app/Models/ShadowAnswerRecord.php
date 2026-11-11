<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Chapter 30, Topic 4: what a candidate prompt or model answered to a real question. Never shown to a customer: nothing renders this table.
class ShadowAnswerRecord extends Model
{
    protected $table = 'shadow_answers';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['gates' => 'array'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
