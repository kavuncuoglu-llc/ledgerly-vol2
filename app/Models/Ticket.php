<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'user_id',
        'subject',
        'body',
        'summary',
        'category',
        'status',
        'source',
        'invoice_number',
        'idempotency_key',
        'urgency',
        'assignee',
        'triaged_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'triaged_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function triageRun(): HasOne
    {
        return $this->hasOne(TriageRun::class)->latestOfMany();
    }

    protected function number(): Attribute
    {
        return Attribute::get(fn (): string => 'T-'.$this->getKey());
    }

    protected function title(): Attribute
    {
        return Attribute::get(fn (): string => $this->subject ?: ($this->summary ?: 'Untitled ticket'));
    }
}
