<?php

namespace App\Ai\Tools\ResendInvoices;

use App\Ai\Schema\Describe;

final readonly class ResendInvoicesArgs
{
    public function __construct(
        #[Describe('Numbers of the invoices to email again, e.g. ["4471", "4461"]. At most ten.', items: 'string')] public array $invoice_numbers,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(array_values($d['invoice_numbers']));
    }

    public static function rules(): array
    {
        return [
            'invoice_numbers' => ['required', 'array', 'min:1', 'max:10'],
            'invoice_numbers.*' => ['string', 'max:32', 'regex:/^\s*#?\s*(INV-)?\d+\s*$/i'],
        ];
    }
}
