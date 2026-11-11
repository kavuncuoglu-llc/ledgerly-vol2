<?php

namespace App\Models;

use App\Casts\Vector;
use Illuminate\Database\Eloquent\Model;

class HelpChunk extends Model
{
    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
        'tokens' => 'integer',
        'tags' => 'array',
        'source_modified_at' => 'datetime',
        'embedding' => Vector::class,
    ];
}
