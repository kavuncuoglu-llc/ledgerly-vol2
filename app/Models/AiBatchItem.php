<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiBatchItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }
}
