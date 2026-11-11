<?php

namespace App\Ai\Tools\ListInvoices;

use App\Ai\Schema\Describe;

final readonly class ListInvoicesArgs
{
    public function __construct(
        #[Describe('Only invoices with this status: draft, sent, overdue, paid or void. Null for every status.')] public ?string $status,
        #[Describe('How many months back to look, 1 to 24. Null for the last 6 months.')] public ?int $months,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self($d['status'] ?? null, isset($d['months']) ? (int) $d['months'] : null);
    }

    public static function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:draft,sent,overdue,paid,void'],
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
        ];
    }
}
