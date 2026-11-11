<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Chapter 34, Topic 8: the receipt for an erasure. It names who by id and says how many of each copy went; it holds no content.
class ErasureRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'deleted' => 'array',
            'anonymized' => 'array',
            'expiring' => 'array',
        ];
    }
}
