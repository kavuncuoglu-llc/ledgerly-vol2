<?php

namespace App\Ai\Privacy;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

// Who an erasure is for: one user ("12"), or every user of a customer account ("customer:8812").
final readonly class ErasureSubject
{
    /** @param Collection<int, User> $users */
    public function __construct(
        public string $label,
        public Collection $users,
        public ?int $customerId = null,
        public ?int $tenantId = null,
    ) {}

    public static function parse(string $raw): self
    {
        if (str_starts_with($raw, 'customer:')) {
            return self::customer((int) substr($raw, 9));
        }

        return self::user((int) $raw);
    }

    public static function user(int $id): self
    {
        return new self("user:{$id}", User::query()->whereKey($id)->get());
    }

    public static function customer(int $id): self
    {
        $customer = Customer::query()->find($id);

        if ($customer === null) {
            return new self("customer:{$id}", collect());
        }

        return new self(
            label: "customer:{$id}",
            users: User::query()->where('customer_id', $id)->get(),
            customerId: $id,
            tenantId: Tenant::query()->where('customer_id', $id)->value('id'),
        );
    }

    public function exists(): bool
    {
        return $this->users->isNotEmpty() || $this->customerId !== null;
    }
}
