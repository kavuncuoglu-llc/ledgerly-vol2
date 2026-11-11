<?php

namespace App\Ai\Schemas;

use App\Ai\Schema\Describe;

final readonly class LineItem
{
    public function __construct(
        #[Describe('What was sold, as printed on the line')] public string $description,
        #[Describe('Quantity, a positive number')] public float $quantity,
        #[Describe('Price of one unit in the invoice currency')] public float $unit_price,
        #[Describe('Line amount in the invoice currency: quantity times unit price')] public float $amount,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            description: (string) $d['description'],
            quantity: (float) $d['quantity'],
            unit_price: (float) $d['unit_price'],
            amount: (float) $d['amount'],
        );
    }
}
