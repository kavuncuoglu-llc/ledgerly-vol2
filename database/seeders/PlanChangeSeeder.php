<?php

namespace Database\Seeders;

use App\Support\Ledgerly;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SeededRandom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanChangeSeeder extends Seeder
{
    private const SEED = 4101;

    private const ACME_CUSTOMER_ID = 12;

    public function run(): void
    {
        $rng = new SeededRandom(self::SEED);

        $rows = [];

        foreach (DB::table('customers')->orderBy('id')->get(['id', 'plan', 'created_at']) as $customer) {
            $signedUpAt = CarbonImmutable::parse($customer->created_at);

            foreach ($this->historyFor($customer->id, $customer->plan, $signedUpAt, $rng) as $change) {
                $rows[] = $change + [
                    'customer_id' => $customer->id,
                    'created_at' => $change['changed_at'],
                    'updated_at' => $change['changed_at'],
                ];
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('plan_changes')->insert($chunk);
        }
    }

    private function historyFor(int $customerId, string $plan, CarbonImmutable $signedUpAt, SeededRandom $rng): array
    {
        if ($customerId === self::ACME_CUSTOMER_ID) {
            return $this->acmeHistory($signedUpAt);
        }

        $path = match ($plan) {
            'enterprise' => ['starter', 'pro', 'enterprise'],
            'pro' => $rng->chance(0.7)
                ? ['starter', 'pro']
                : ['pro'],
            default => $rng->chance(0.15)
                ? ['pro', 'starter']
                : ['starter'],
        };

        return $this->rowsFor($path, $signedUpAt, $rng);
    }

    private function acmeHistory(CarbonImmutable $signedUpAt): array
    {
        return [
            $this->row(null, 'starter', 'signup', 'self_serve', $signedUpAt),
            $this->row('starter', 'pro', 'upgrade', 'self_serve', CarbonImmutable::parse('2026-01-14 15:42:00'), 'Reached the starter seat limit.'),
        ];
    }

    private function rowsFor(array $path, CarbonImmutable $signedUpAt, SeededRandom $rng): array
    {
        $rows = [$this->row(null, $path[0], 'signup', 'self_serve', $signedUpAt)];

        $at = $signedUpAt;

        for ($step = 1; $step < count($path); $step++) {
            $at = $this->nextChangeDate($at, $rng);

            $rows[] = $this->row(
                from: $path[$step - 1],
                to: $path[$step],
                type: $this->typeOf($path[$step - 1], $path[$step]),
                via: $this->viaFor($path[$step - 1], $path[$step], $rng),
                at: $at,
            );
        }

        return $rows;
    }

    private function nextChangeDate(CarbonImmutable $after, SeededRandom $rng): CarbonImmutable
    {
        $latest = CarbonImmutable::parse(Ledgerly::today()->toDateString().' 09:00:00')->subDays(3);

        $candidate = $after->addDays($rng->int(60, 240))->setTime($rng->int(8, 17), $rng->int(0, 59));

        return $candidate->greaterThan($latest)
            ? $latest
            : $candidate;
    }

    private function typeOf(string $from, string $to): string
    {
        $rank = ['starter' => 1, 'pro' => 2, 'enterprise' => 3];

        return $rank[$to] > $rank[$from]
            ? 'upgrade'
            : 'downgrade';
    }

    private function viaFor(string $from, string $to, SeededRandom $rng): string
    {
        if ($to === 'enterprise') {
            return 'sales';
        }

        if ($this->typeOf($from, $to) === 'downgrade') {
            return 'renewal';
        }

        return $rng->pick(['self_serve', 'self_serve', 'sales']);
    }

    private function row(?string $from, string $to, string $type, string $via, CarbonImmutable $at, ?string $note = null): array
    {
        return [
            'from_plan' => $from,
            'to_plan' => $to,
            'change_type' => $type,
            'via' => $via,
            'note' => $note,
            'changed_at' => $at->format('Y-m-d H:i:s'),
        ];
    }
}
