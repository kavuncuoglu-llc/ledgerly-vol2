<?php

namespace Database\Factories;

use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'user_id' => null,
            'subject' => fake()->sentence(6),
            'body' => fake()->paragraph(),
            'summary' => null,
            'category' => null,
            'status' => TicketStatus::Open,
            'source' => 'email',
            'invoice_number' => null,
        ];
    }

    public function billing(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'billing',
            'subject' => 'Client says they paid but the invoice shows unpaid',
            'body' => 'My client says they paid on March 3 but the invoice still shows unpaid. Can you check?',
        ]);
    }

    public function howTo(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'howto',
            'subject' => 'Recurring invoice for a retainer client',
            'body' => 'How do I set up a recurring invoice for a retainer client?',
        ]);
    }
}
