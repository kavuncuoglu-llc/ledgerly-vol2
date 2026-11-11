<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Chapter 42 (the Build's `registry:add`) and Chapter 44, Topic 6: one version of one fine-tune, with its provenance, its artifacts and their hashes, the evaluation results behind it, and the history of its stage.
class ModelRegistryEntry extends Model
{
    public const STAGES = ['candidate', 'shadow', 'canary', 'production', 'previous', 'retired', 'rejected'];

    protected $table = 'model_registry';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'artifacts' => 'array',
            'evaluations' => 'array',
            'history' => 'array',
            'tools' => 'array',
            'signatures' => 'array',
            'served_on' => 'array',
            'promoted_at' => 'datetime',
            'warm_until' => 'datetime',
            'retired_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function label(): string
    {
        return "{$this->name} v{$this->version}";
    }
}
