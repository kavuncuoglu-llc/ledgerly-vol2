<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ToolCall extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'duration_ms' => 'integer',
        ];
    }
}
