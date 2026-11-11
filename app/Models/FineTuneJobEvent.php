<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FineTuneJobEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'mlflow_logged' => 'boolean', 'occurred_at' => 'datetime'];
    }
}
