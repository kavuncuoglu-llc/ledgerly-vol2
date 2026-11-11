<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Chapter 40, Topic 2: when a support agent heavily edits the assistant's draft before sending it, the edited reply is stored beside the draft. It is the richest training example the app has, and a ready-made preference pair (Chapter 39).
class ReplyEdit extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
