<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'customer_id' => null,
            'tenant_id' => Tenant::factory(),
            'role' => 'viewer',
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(?Customer $customer = null): static
    {
        $customer ??= Customer::factory()->create();

        $tenant = Tenant::query()->firstOrCreate(['customer_id' => $customer->id], ['name' => $customer->name]);

        return $this->state(fn (array $attributes) => [
            'customer_id' => $customer->id,
            'tenant_id' => $tenant->id,
            'role' => 'owner',
        ]);
    }

    public function support(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => null,
            'tenant_id' => null,
            'role' => 'support',
        ]);
    }

    public function inTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
