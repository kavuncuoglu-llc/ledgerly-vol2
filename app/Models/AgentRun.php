<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'trace' => 'array',
            'seconds' => 'float',
        ];
    }
}
