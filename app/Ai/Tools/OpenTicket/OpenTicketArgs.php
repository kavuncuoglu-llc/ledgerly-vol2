<?php

namespace App\Ai\Tools\OpenTicket;

use App\Ai\Schema\Describe;

final readonly class OpenTicketArgs
{
    public function __construct(
        #[Describe('One or two sentences for the support team: what the customer reported and what you found. No personal data beyond what the customer wrote.')] public string $summary,
        #[Describe('Invoice number the ticket is about, e.g. 4471. Null if no invoice is involved.')] public ?string $invoice_number,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self($d['summary'], $d['invoice_number'] ?? null);
    }

    public static function rules(): array
    {
        return [
            'summary' => ['required', 'string', 'min:5', 'max:500'],
            'invoice_number' => ['nullable', 'string', 'max:32', 'regex:/^\s*#?\s*(INV-)?\d+\s*$/i'],
        ];
    }
}
