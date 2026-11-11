<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Ledgerly;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => fake()->numberBetween(5000, 200000) / 100,
            'method' => fake()->randomElement(['card', 'ach', 'bank_transfer', 'check']),
            'reference' => strtoupper(fake()->bothify('PAY-########')),
            'paid_at' => Ledgerly::today()->subDays(fake()->numberBetween(1, 10))->setTime(12, 0),
        ];
    }
}
