<?php

namespace App\Ai\Security;

use App\Models\Invoice;

final class ForeignIdentifierScan
{
    private const PATTERN = '/(?:\bINV-|\binvoice\s+(?:number\s+)?#?\s*|#)(\d{3,8})\b/i';

    public function foreignInvoiceNumbers(string $text, int $customerId): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        $candidates = collect($matches[1])
            ->map(fn (string $digits) => 'INV-'.$digits)
            ->unique()
            ->values()
            ->all();

        if ($candidates === []) {
            return [];
        }

        return Invoice::query()
            ->whereIn('number', $candidates)
            ->where('customer_id', '!=', $customerId)
            ->pluck('number')
            ->all();
    }
}
