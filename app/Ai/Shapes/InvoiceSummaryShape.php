<?php

namespace App\Ai\Shapes;

use App\Ai\Encoding\Shape;
use App\Ai\Privacy\DeclaredFields;
use App\Models\Invoice;
use App\Support\Ledgerly;
use Illuminate\Support\Collection;

final class InvoiceSummaryShape implements Shape
{
    // Chapter 34, Topic 3: the same invoice, before and after minimization. No billing contact, address, tax id or notes: those are not in this list, so no column added to Invoice reaches a prompt.
    public const FIELDS = [
        'number' => null,
        'status' => null,
        'due' => null,
        'total' => null,
        'late_fee' => null,
        'days_overdue' => null,
    ];

    public function __construct(private ?int $limit = null) {}

    public function fields(): array
    {
        return DeclaredFields::select([
            'number' => fn (Invoice $i) => $i->number,
            'status' => fn (Invoice $i) => $i->status->value,
            'due' => fn (Invoice $i) => $i->due_at->toDateString(),           // date, not timestamp
            'total' => fn (Invoice $i) => round($i->total, 2),                  // two decimals
            'late_fee' => fn (Invoice $i) => round($i->lateFee(), 2),            // dollars, so the model does not convert cents
            'days_overdue' => fn (Invoice $i) => $i->daysOverdue(),             // computed, so the model doesn't
        ], self::FIELDS);
    }

    public function header(Collection $rows): ?string
    {
        $currency = $rows->first()?->currency;

        if ($currency === null) {
            return null;
        }

        return 'Amounts in '.$currency.'. Today is '.Ledgerly::today()->toDateString().'.';   // stated once
    }

    public function limit(): int
    {
        return $this->limit ?? 25;   // from the feature's budget
    }
}
