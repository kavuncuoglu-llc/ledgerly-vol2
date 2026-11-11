<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineJudgment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['score' => 'float'];
    }
}
