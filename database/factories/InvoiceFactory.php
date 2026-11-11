<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Ledgerly;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(20000, 400000) / 100;
        $tax = round($subtotal * 0.07, 2);
        $issuedAt = Ledgerly::today()->subDays(fake()->numberBetween(1, 20))->setTime(10, 0);

        return [
            'customer_id' => Customer::factory(),
            'number' => 'INV-'.fake()->unique()->numberBetween(10000, 99999),
            'status' => InvoiceStatus::Sent,
            'currency' => 'USD',
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
            'issued_at' => $issuedAt,
            'due_at' => $issuedAt->addDays(30)->startOfDay(),
            'paid_at' => null,
            'late_fee_cents' => 0,
            'notes' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Draft,
        ]);
    }

    public function overdue(int $daysOverdue = 18): static
    {
        return $this->state(function (array $attributes) use ($daysOverdue) {
            $dueAt = Ledgerly::today()->subDays($daysOverdue);

            return [
                'status' => InvoiceStatus::Overdue,
                'issued_at' => $dueAt->subDays(30)->setTime(10, 0),
                'due_at' => $dueAt,
            ];
        });
    }

    public function paid(): static
    {
        return $this->state(function (array $attributes) {
            $paidAt = Ledgerly::today()->subDays(3)->setTime(14, 0);

            return [
                'status' => InvoiceStatus::Paid,
                'issued_at' => $paidAt->subDays(20),
                'due_at' => $paidAt->subDays(20)->addDays(30)->startOfDay(),
                'paid_at' => $paidAt,
            ];
        });
    }

    public function withLateFee(int $cents = 2160): static
    {
        return $this->state(fn (array $attributes) => [
            'late_fee_cents' => $cents,
        ]);
    }
}
