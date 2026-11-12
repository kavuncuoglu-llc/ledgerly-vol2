<?php

namespace App\Ai\Tools\CheckGoodwillCredit;

use App\Ai\Schema\Describe;

final readonly class CheckGoodwillCreditArgs
{
    public function __construct(
        #[Describe('Invoice number the customer wants a credit on, e.g. 4471 or INV-4471.')] public string $invoice_number,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self($d['invoice_number']);
    }

    public static function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:32', 'regex:/^\s*#?\s*(INV-)?\d+\s*$/i'],
        ];
    }
}
