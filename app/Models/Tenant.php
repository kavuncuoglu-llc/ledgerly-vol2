<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'customer_id',
        'training_use',
        'training_terms_version',
        'data_residency',
    ];

    protected function casts(): array
    {
        return [
            'training_use' => 'boolean',
        ];
    }

    // Chapter 40, Topic 3: why a tenant's conversations may be training data, written for the dataset manifest. Empty when they may not be.
    public function trainingConsentBasis(): string
    {
        if (! $this->training_use) {
            return '';
        }

        return 'tenant opt-in under terms '.($this->training_terms_version ?: 'unversioned');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
