<?php

namespace App\Ai\Tools\LookupInvoice;

use App\Ai\Schema\Describe;

final readonly class LookupInvoiceArgs
{
    public function __construct(
        #[Describe('Invoice number as the customer wrote it, e.g. 4471 or #4471. Null if they gave a month instead.')] public ?string $invoice_number,
        #[Describe('Month the customer referred to, as YYYY-MM. Null if they gave an invoice number.')] public ?string $month,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self($d['invoice_number'] ?? null, $d['month'] ?? null);
    }

    public static function rules(): array
    {
        return [
            'invoice_number' => ['nullable', 'string', 'max:32', 'regex:/^\s*#?\s*(INV-)?\d+\s*$/i', 'required_without:month'],
            'month' => ['nullable', 'string', 'date_format:Y-m', 'required_without:invoice_number'],
        ];
    }
}
