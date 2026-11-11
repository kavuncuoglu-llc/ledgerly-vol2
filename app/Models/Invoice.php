<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Support\Ledgerly;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'number',
        'status',
        'currency',
        'subtotal',
        'tax',
        'total',
        'issued_at',
        'due_at',
        'paid_at',
        'late_fee_cents',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'late_fee_cents' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(InvoiceLineItem::class)->orderBy('position');
    }

    public function reminderDraft(): HasOne
    {
        return $this->hasOne(ReminderDraft::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_at');
    }

    public function scopeWhereNumber(Builder $query, string $number): Builder
    {
        $digits = preg_replace('/^[#\s]*(?:INV-?)?/i', '', trim($number));

        if (ctype_digit($digits)) {
            return $query->where('number', 'INV-'.$digits);
        }

        return $query->where('number', trim($number));
    }

    public function scopeWithLateFee(Builder $query): Builder
    {
        return $query->where('late_fee_cents', '>', 0);
    }

    public function daysOverdue(): int
    {
        if (! $this->status->isOpen()) {
            return 0;
        }

        $dueDate = $this->due_at->copy()->startOfDay();

        return max(0, (int) $dueDate->diffInDays(Ledgerly::today(), false));
    }

    public function lateFee(): float
    {
        return $this->late_fee_cents / 100;
    }

    public function amountPaid(): float
    {
        return round((float) $this->payments->sum('amount'), 2);
    }

    public function balanceDue(): float
    {
        if ($this->status === InvoiceStatus::Void) {
            return 0.0;
        }

        return round((float) $this->total + $this->lateFee() - $this->amountPaid(), 2);
    }

    public function isPartiallyPaid(): bool
    {
        if ($this->amountPaid() <= 0) {
            return false;
        }

        return $this->balanceDue() > 0;
    }
}
