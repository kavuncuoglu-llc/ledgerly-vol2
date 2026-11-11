<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExperimentSetting extends Model
{
    protected $table = 'experiments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['percent' => 'integer'];
    }
}
