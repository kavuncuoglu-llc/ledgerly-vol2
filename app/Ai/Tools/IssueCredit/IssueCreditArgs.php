<?php

namespace App\Ai\Tools\IssueCredit;

use App\Ai\Schema\Describe;

final readonly class IssueCreditArgs
{
    public function __construct(
        #[Describe('Invoice number the credit applies to, e.g. 4471 or INV-4471.')] public string $invoice_number,
        #[Describe('Amount of the credit in cents, a whole number, e.g. 1800 for $18.00.')] public int $amount_cents,
        #[Describe('One sentence: why the customer says a credit is due.')] public string $reason,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self($d['invoice_number'], (int) $d['amount_cents'], trim($d['reason']));
    }

    public static function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:32', 'regex:/^\s*#?\s*(INV-)?\d+\s*$/i'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:100000'],
            'reason' => ['required', 'string', 'min:5', 'max:200'],
        ];
    }
}
