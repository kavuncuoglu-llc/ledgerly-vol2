<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnswerSignal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'computed_on' => 'date',
            'reasked' => 'boolean',
            'ticket_followup' => 'boolean',
            'escalated' => 'boolean',
            'resolved' => 'boolean',
        ];
    }
}
