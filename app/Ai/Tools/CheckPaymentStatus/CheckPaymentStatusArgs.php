<?php

namespace App\Ai\Tools\CheckPaymentStatus;

use App\Ai\Schema\Describe;

final readonly class CheckPaymentStatusArgs
{
    public function __construct(
        #[Describe('Invoice number as the customer wrote it, e.g. 4471, #4471 or INV-4471.')] public string $invoice_number,
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
