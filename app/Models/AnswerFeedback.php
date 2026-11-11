<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Chapter 30, Topic 3: a thumbs click, stored with the model call, the prompt version, the provider and the experiment variant that wrote the answer, so feedback can be split by variant.
class AnswerFeedback extends Model
{
    use BelongsToTenant;

    public const REASONS = ['wrong_answer', 'did_not_answer', 'wanted_person'];

    protected $table = 'answer_feedback';

    protected $guarded = [];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
