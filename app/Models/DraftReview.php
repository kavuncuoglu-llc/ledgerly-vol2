<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Chapter 51, Topic 8: how a person handled a draft. Only what is needed to see reliance: whether it was edited, how long the review took, and what the weekly audit found.
class DraftReview extends Model
{
    protected $fillable = [
        'draft_type',
        'draft_id',
        'reviewer_id',
        'sent_at',
        'edit_distance',
        'review_seconds',
        'audited',
        'audit_found_error',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'audited' => 'boolean',
            'audit_found_error' => 'boolean',
        ];
    }
}
