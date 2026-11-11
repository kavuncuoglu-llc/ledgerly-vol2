<?php

namespace App\Models;

use App\Casts\Vector;
use Illuminate\Database\Eloquent\Model;

class CachedAnswer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
            'hit_count' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
