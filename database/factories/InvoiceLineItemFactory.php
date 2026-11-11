<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLineItem>
 */
class InvoiceLineItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 20);
        $unitPrice = fake()->numberBetween(2500, 20000) / 100;

        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->randomElement(['Consulting hours', 'Design retainer', 'Monthly maintenance', 'Workshop day']),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $quantity * $unitPrice,
            'position' => 1,
        ];
    }
}
