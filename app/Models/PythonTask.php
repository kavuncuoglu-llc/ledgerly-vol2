<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PythonTask extends Model
{
    public const QUEUED = 'queued';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $primaryKey = 'task_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'item_count' => 'integer',
            'applied_count' => 'integer',
            'elapsed_ms' => 'integer',
            'queued_at' => 'datetime',
            'deadline_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
