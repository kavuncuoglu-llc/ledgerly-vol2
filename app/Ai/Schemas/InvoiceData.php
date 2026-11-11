<?php

namespace App\Ai\Schemas;

use App\Ai\Schema\Describe;
use App\Ai\Structured\ExtractionMeta;
use App\Support\Currency;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

final readonly class InvoiceData
{
    public function __construct(
        #[Describe('Vendor or supplier name as printed')] public ?string $vendor_name,
        #[Describe('Invoice number or reference, as printed')] public ?string $invoice_number,
        #[Describe('Invoice date, ISO 8601 (YYYY-MM-DD)')] public ?string $invoice_date,
        #[Describe('Due date, ISO 8601; null if not stated')] public ?string $due_date,
        #[Describe('ISO 4217 currency code')] public ?string $currency,
        /** @var LineItem[] */
        #[Describe('Each line item')] public array $line_items,
        #[Describe('Subtotal before tax; null if not printed')] public ?float $subtotal,
        #[Describe('Total tax; null if not printed')] public ?float $tax,
        #[Describe('Grand total as printed')] public ?float $total,
        public ?ExtractionMeta $meta = null,
    ) {}

    public static function fromArray(array $d, ?ExtractionMeta $meta = null): self
    {
        return new self(
            vendor_name: $d['vendor_name'] ?? null,
            invoice_number: $d['invoice_number'] ?? null,
            invoice_date: $d['invoice_date'] ?? null,
            due_date: $d['due_date'] ?? null,
            currency: $d['currency'] ?? null,
            line_items: array_map(LineItem::fromArray(...), $d['line_items'] ?? []),
            subtotal: isset($d['subtotal']) ? (float) $d['subtotal'] : null,
            tax: isset($d['tax']) ? (float) $d['tax'] : null,
            total: isset($d['total']) ? (float) $d['total'] : null,
            meta: $meta,
        );
    }

    public static function rules(): array
    {
        return [
            'vendor_name' => ['nullable', 'string', 'max:200'],
            'invoice_number' => ['nullable', 'string', 'max:64'],
            'invoice_date' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(Currency::codes())],
            'line_items' => ['array', 'max:200'],
            'line_items.*.description' => ['required', 'string', 'max:500'],
            'line_items.*.quantity' => ['required', 'numeric', 'min:0'],
            'line_items.*.unit_price' => ['required', 'numeric'],
            'line_items.*.amount' => ['required', 'numeric'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public static function afterRules(): array
    {
        return [
            // cross-field: the arithmetic has to hold
            function (Validator $v) {
                if ($v->errors()->isNotEmpty()) {
                    return;
                }

                $d = $v->getData();

                $lines = collect($d['line_items'] ?? [])->sum('amount');

                if (isset($d['subtotal']) && abs($lines - $d['subtotal']) > 0.01) {
                    $v->errors()->add('subtotal', sprintf('Line items sum to %s, not %s.', number_format($lines, 2, '.', ''), number_format($d['subtotal'], 2, '.', '')));
                }

                if (isset($d['subtotal'], $d['tax'], $d['total']) && abs(($d['subtotal'] + $d['tax']) - $d['total']) > 0.01) {
                    $v->errors()->add('total', 'Total does not equal subtotal plus tax.');
                }
            },
        ];
    }
}
