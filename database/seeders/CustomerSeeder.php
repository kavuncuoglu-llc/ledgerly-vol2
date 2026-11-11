<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\ResetsSequences;
use Database\Seeders\Support\SeededRandom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerSeeder extends Seeder
{
    use ResetsSequences;

    private const SEED = 4001;

    private const PASSWORD_HASH = '$2y$12$wRW3Upi0Xzw2W8HxO6gR3OpQeBn9oeZA49asq5BacGnhbGRsDIyEK';

    private const CURRENCIES = [
        'DE' => 'EUR',
        'GB' => 'GBP',
        'CA' => 'CAD',
        'AU' => 'AUD',
    ];

    private const MEMBER_FIRST_NAMES = ['Aiden', 'Bianca', 'Cormac', 'Dalia', 'Emeka', 'Freya', 'Gavin', 'Hana', 'Ilya', 'Jovita', 'Kofi', 'Lena', 'Mikael', 'Nadia', 'Orla', 'Pavel'];

    private const MEMBER_LAST_NAMES = ['Abbott', 'Bellamy', 'Castellano', 'Dunmore', 'Eriksen', 'Faraday', 'Gutierrez', 'Hargreaves', 'Ibarra', 'Jankowski', 'Kerrigan', 'Lindqvist'];

    private const MEMBER_ROLES = ['admin', 'biller', 'viewer', 'biller'];

    public function run(): void
    {
        $rng = new SeededRandom(self::SEED);
        $createdAt = CarbonImmutable::parse('2025-03-01 09:00:00');
        $passwordHash = self::PASSWORD_HASH;

        $customers = [];
        $users = [];
        $nextMemberId = 41;

        foreach (require __DIR__.'/data/customers.php' as $id => [$name, $contact, $plan, $industry, $country, $timezone, $status]) {
            $slug = Str::slug(str_replace('&', 'and', $name));
            $domain = $slug.'.example.com';
            $seats = $this->seatsFor($plan, $rng);
            $signedUpAt = $createdAt->addDays($rng->int(0, 40))->format('Y-m-d H:i:s');

            $customers[] = [
                'id' => $id,
                'name' => $name,
                'contact_name' => $contact,
                'email' => 'billing@'.$domain,
                'plan' => $plan,
                'status' => $status,
                'currency' => self::CURRENCIES[$country] ?? 'USD',
                'country' => $country,
                'timezone' => $timezone,
                'seats' => $seats,
                'created_at' => $signedUpAt,
                'updated_at' => $signedUpAt,
            ];

            $users[] = $this->userRow($id, $contact, $domain, $id, 'owner', $passwordHash, $signedUpAt);

            for ($position = 1; $position < min($seats, 4); $position++) {
                $memberName = $rng->pick(self::MEMBER_FIRST_NAMES).' '.$rng->pick(self::MEMBER_LAST_NAMES);
                $users[] = $this->userRow($nextMemberId, $memberName, $domain, $id, self::MEMBER_ROLES[$position - 1], $passwordHash, $signedUpAt);
                $nextMemberId++;
            }
        }

        $users[] = $this->staffRow($nextMemberId++, 'Priya Raman', 'priya.raman@ledgerly.test', $passwordHash, $createdAt);
        $users[] = $this->staffRow($nextMemberId, 'Tomas Lindqvist', 'tomas.lindqvist@ledgerly.test', $passwordHash, $createdAt);

        DB::table('customers')->insert($customers);

        foreach (array_chunk($users, 100) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $this->resetSequence('customers');
        $this->resetSequence('users');
    }

    private function seatsFor(string $plan, SeededRandom $rng): int
    {
        return match ($plan) {
            'starter' => 1,
            'pro' => $rng->int(2, 5),
            default => $rng->int(6, 12),
        };
    }

    private function userRow(int $id, string $name, string $domain, int $customerId, string $role, string $passwordHash, string $at): array
    {
        $localPart = Str::slug($name, '.');

        return [
            'id' => $id,
            'name' => $name,
            'email' => $localPart.'@'.$domain,
            'email_verified_at' => $at,
            'password' => $passwordHash,
            'customer_id' => $customerId,
            'role' => $role,
            'remember_token' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    private function staffRow(int $id, string $name, string $email, string $passwordHash, CarbonImmutable $at): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'email_verified_at' => $at->format('Y-m-d H:i:s'),
            'password' => $passwordHash,
            'customer_id' => null,
            'role' => 'support',
            'remember_token' => null,
            'created_at' => $at->format('Y-m-d H:i:s'),
            'updated_at' => $at->format('Y-m-d H:i:s'),
        ];
    }
}
