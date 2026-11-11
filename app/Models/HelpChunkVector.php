<?php

namespace App\Models;

use App\Casts\Vector;
use Illuminate\Database\Eloquent\Model;

class HelpChunkVector extends Model
{
    protected $guarded = [];

    protected $casts = [
        'embedding' => Vector::class,
    ];
}
