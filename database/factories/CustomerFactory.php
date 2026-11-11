<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->userName().'@example.com',
            'plan' => fake()->randomElement(['starter', 'pro', 'enterprise']),
            'status' => 'active',
            'currency' => 'USD',
            'country' => 'US',
            'timezone' => 'America/New_York',
            'seats' => fake()->numberBetween(1, 5),
        ];
    }
}
