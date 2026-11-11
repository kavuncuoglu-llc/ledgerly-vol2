<?php

namespace App\Ai\Schemas;

use App\Ai\Schema\Describe;
use App\Ai\Structured\ExtractionMeta;
use Illuminate\Validation\Rule;

final readonly class TicketData
{
    public function __construct(
        #[Describe('Category of the message; other if none of the others fits')] public Category $category,
        #[Describe('Invoice number mentioned, digits only as written; null if none')] public ?string $invoice_number,
        #[Describe('Customer email address mentioned; null if none')] public ?string $customer_email,
        #[Describe('Money amount mentioned, positive number without currency symbol; null if none')] public ?float $amount_mentioned,
        #[Describe('Date mentioned, ISO 8601 (YYYY-MM-DD); null if no date or the year is not stated')] public ?string $date_mentioned,
        public ?ExtractionMeta $meta = null,
    ) {}

    public static function fromArray(array $d, ?ExtractionMeta $meta = null): self
    {
        return new self(
            category: Category::from($d['category']),
            invoice_number: $d['invoice_number'] ?? null,
            customer_email: $d['customer_email'] ?? null,
            amount_mentioned: isset($d['amount_mentioned']) ? (float) $d['amount_mentioned'] : null,
            date_mentioned: $d['date_mentioned'] ?? null,
            meta: $meta,
        );
    }

    public static function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(Category::class)],
            'invoice_number' => ['nullable', 'string', 'max:16', 'regex:/^(INV-)?\d{3,8}$/i'],     // Chapter 32: a pattern, so the field cannot carry a sentence to the privileged step
            'customer_email' => ['nullable', 'email', 'max:200'],
            'amount_mentioned' => ['nullable', 'numeric', 'min:0'],
            'date_mentioned' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
        ];
    }

    public static function afterRules(): array
    {
        return [];
    }
}
