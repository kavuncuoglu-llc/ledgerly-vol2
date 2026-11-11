<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\ResetsSequences;
use Database\Seeders\Support\SeededRandom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InvoiceSeeder extends Seeder
{
    use ResetsSequences;

    private const SEED = 20260420;

    private const ANCHOR = '2026-04-20';

    private const FIRST_ID = 3951;

    private const COUNT = 600;

    private const ACME_ID = 12;

    private const ACME_PAID_SLOTS = [312, 340, 372, 401, 430, 452, 470, 495, 510];

    private const ACME_SENT_SLOTS = [570, 580, 590];

    private const ACME_OVERDUE_SLOT = 487;

    private const INV_4471_SLOT = 520;

    private const DATE_ANCHORS = [
        [0, '2025-04-21'],
        [487, '2026-02-08'],
        [520, '2026-03-03'],
        [599, '2026-04-17'],
    ];

    private const TERMS = [30, 30, 30, 30, 30, 30, 14, 14, 7, 45, 45, 60, 0];

    private const PAYMENT_METHODS = ['card' => 55, 'ach' => 20, 'bank_transfer' => 15, 'check' => 7, 'cash' => 3];

    private CarbonImmutable $anchor;

    private SeededRandom $rng;

    private array $invoices = [];

    private array $lineItems = [];

    private array $payments = [];

    public function run(): void
    {
        $this->rng = new SeededRandom(self::SEED);
        $this->anchor = CarbonImmutable::parse(self::ANCHOR)->startOfDay();

        $customers = DB::table('customers')->orderBy('id')->get()->keyBy('id');
        $industries = require __DIR__.'/data/line_items.php';
        $customerData = require __DIR__.'/data/customers.php';
        $slotOwners = $this->assignSlotsToCustomers($customers->all());

        foreach (range(0, self::COUNT - 1) as $slot) {
            $customerId = $slotOwners[$slot];
            $customer = $customers[$customerId];
            $catalog = $industries[$customerData[$customerId][3]];

            $this->buildInvoice($slot, $customer, $catalog);
        }

        foreach (array_chunk($this->invoices, 150) as $chunk) {
            DB::table('invoices')->insert($chunk);
        }

        foreach (array_chunk($this->lineItems, 300) as $chunk) {
            DB::table('invoice_line_items')->insert($chunk);
        }

        foreach (array_chunk($this->payments, 300) as $chunk) {
            DB::table('payments')->insert($chunk);
        }

        $this->resetSequence('invoices');
        $this->resetSequence('invoice_line_items');
        $this->resetSequence('payments');
    }

    private function assignSlotsToCustomers(array $customers): array
    {
        $pinned = [];

        foreach (array_merge(self::ACME_PAID_SLOTS, self::ACME_SENT_SLOTS, [self::ACME_OVERDUE_SLOT, self::INV_4471_SLOT]) as $slot) {
            $pinned[$slot] = self::ACME_ID;
        }

        $weights = [];

        foreach ($customers as $id => $customer) {
            $weights[$id] = match (true) {
                $id === self::ACME_ID => 0,
                $customer->status === 'trial' => 2,
                $customer->status === 'read_only' => 3,
                default => $this->rng->int(4, 14),
            };
        }

        $weights = array_filter($weights);
        $owners = [];

        foreach (range(0, self::COUNT - 1) as $slot) {
            $owners[$slot] = $pinned[$slot] ?? $this->rng->weighted($weights);
        }

        return $owners;
    }

    private function buildInvoice(int $slot, object $customer, array $catalog): void
    {
        if ($slot === self::INV_4471_SLOT) {
            $this->buildInvoice4471($slot, $customer);

            return;
        }

        if ($slot === self::ACME_OVERDUE_SLOT) {
            $this->buildInvoice4438($slot, $customer);

            return;
        }

        $id = self::FIRST_ID + $slot;
        $isAcme = (int) $customer->id === self::ACME_ID;
        $issuedAt = $this->issuedAtFor($slot);
        $terms = $isAcme ? 30 : $this->rng->pick(self::TERMS);
        $dueAt = $issuedAt->startOfDay()->addDays($terms);
        $lines = $this->buildLines($catalog, $isAcme ? $this->rng->int(2, 3) : $this->rng->int(1, 4));
        $status = $this->resolveStatus($slot, $isAcme, $issuedAt, $dueAt);

        $this->finishInvoice($id, $customer, $issuedAt, $dueAt, $lines, $status);
    }

    private function resolveStatus(int $slot, bool $isAcme, CarbonImmutable $issuedAt, CarbonImmutable $dueAt): string
    {
        if ($isAcme) {
            return in_array($slot, self::ACME_SENT_SLOTS, true) ? 'sent' : 'paid';
        }

        if ($slot >= 580 && $this->rng->chance(0.4)) {
            return 'draft';
        }

        if ($dueAt >= $this->anchor) {
            return $this->paidEarly($issuedAt)
                ? 'paid'
                : 'sent';
        }

        if ($this->rng->chance(0.02)) {
            return 'void';
        }

        $daysPastDue = (int) $dueAt->diffInDays($this->anchor);
        $unpaidChance = $daysPastDue <= 120
            ? 0.28
            : 0.015;

        return $this->rng->chance($unpaidChance)
            ? 'overdue'
            : 'paid';
    }

    private function paidEarly(CarbonImmutable $issuedAt): bool
    {
        if ($issuedAt->diffInDays($this->anchor) < 5) {
            return false;
        }

        return $this->rng->chance(0.25);
    }

    private function finishInvoice(int $id, object $customer, CarbonImmutable $issuedAt, CarbonImmutable $dueAt, array $lines, string $status): void
    {
        $subtotalCents = array_sum(array_column($lines, 'amount_cents'));
        $taxCents = $this->taxCents($lines, $customer);
        $totalCents = $subtotalCents + $taxCents;

        $paidAt = null;
        $feeCents = 0;
        $notes = null;
        $updatedAt = $issuedAt;
        $payments = [];

        if ($status === 'paid') {
            [$paidAt, $feeCents] = $this->paidTiming($issuedAt, $dueAt, $totalCents);
            $payments = $this->settlementPayments($customer, $issuedAt, $paidAt, $totalCents + $feeCents);
            $updatedAt = $paidAt;
        }

        if ($status === 'sent') {
            $payments = $this->partialPayments($customer, $issuedAt, $totalCents);
        }

        if ($status === 'overdue') {
            $payments = $this->partialPayments($customer, $issuedAt, $totalCents);
            $balanceCents = $totalCents - array_sum(array_column($payments, 'amount_cents'));
            $daysPastDue = (int) $dueAt->diffInDays($this->anchor);

            [$feeCents, $notes] = $this->overdueFeeAndNotes($balanceCents, $daysPastDue);
            $updatedAt = $this->nightlyUpdate($feeCents, $dueAt);
        }

        if ($status === 'void') {
            $notes = 'Voided and reissued with corrected details.';
            $updatedAt = $issuedAt->addDays(2);
        }

        $this->recordInvoice($id, $customer, $status, $subtotalCents, $taxCents, $totalCents, $issuedAt, $dueAt, $paidAt, $feeCents, $notes, $updatedAt, $lines, $payments);
    }

    private function recordInvoice(
        int $id,
        object $customer,
        string $status,
        int $subtotalCents,
        int $taxCents,
        int $totalCents,
        CarbonImmutable $issuedAt,
        CarbonImmutable $dueAt,
        ?CarbonImmutable $paidAt,
        int $feeCents,
        ?string $notes,
        CarbonImmutable $updatedAt,
        array $lines,
        array $payments,
    ): void {
        $this->invoices[] = [
            'id' => $id,
            'customer_id' => $customer->id,
            'number' => 'INV-'.$id,
            'status' => $status,
            'currency' => $customer->currency,
            'subtotal' => $this->decimal($subtotalCents),
            'tax' => $this->decimal($taxCents),
            'total' => $this->decimal($totalCents),
            'issued_at' => $issuedAt->format('Y-m-d H:i:s'),
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'paid_at' => $paidAt?->format('Y-m-d H:i:s'),
            'late_fee_cents' => $feeCents,
            'notes' => $notes,
            'created_at' => $issuedAt->format('Y-m-d H:i:s'),
            'updated_at' => $updatedAt->format('Y-m-d H:i:s'),
            'deleted_at' => null,
        ];

        foreach ($lines as $position => $line) {
            $this->lineItems[] = [
                'id' => count($this->lineItems) + 1,
                'invoice_id' => $id,
                'description' => $line['description'],
                'quantity' => $this->decimal($line['quantity'] * 100),
                'unit_price' => $this->decimal($line['unit_cents']),
                'amount' => $this->decimal($line['amount_cents']),
                'position' => $position + 1,
                'created_at' => $issuedAt->format('Y-m-d H:i:s'),
                'updated_at' => $issuedAt->format('Y-m-d H:i:s'),
            ];
        }

        foreach ($payments as $payment) {
            $this->payments[] = [
                'id' => count($this->payments) + 1,
                'invoice_id' => $id,
                'amount' => $this->decimal($payment['amount_cents']),
                'method' => $payment['method'],
                'reference' => $payment['reference'],
                'paid_at' => $payment['paid_at']->format('Y-m-d H:i:s'),
                'created_at' => $payment['paid_at']->format('Y-m-d H:i:s'),
                'updated_at' => $payment['paid_at']->format('Y-m-d H:i:s'),
            ];
        }
    }

    private function buildInvoice4471(int $slot, object $customer): void
    {
        $lines = [
            $this->pinnedLine('Brand identity: design phase', 1, 80000),
            $this->pinnedLine('Revisions and handoff', 4, 10000),
        ];

        $this->recordInvoice(
            id: self::FIRST_ID + $slot,
            customer: $customer,
            status: 'overdue',
            subtotalCents: 120000,
            taxCents: 8450,
            totalCents: 128450,
            issuedAt: CarbonImmutable::parse('2026-03-03 09:14:22'),
            dueAt: CarbonImmutable::parse('2026-04-02 00:00:00'),
            paidAt: null,
            feeCents: 2160,
            notes: null,
            updatedAt: CarbonImmutable::parse('2026-04-20 06:00:01'),
            lines: $lines,
            payments: [],
        );
    }

    private function buildInvoice4438(int $slot, object $customer): void
    {
        $this->recordInvoice(
            id: self::FIRST_ID + $slot,
            customer: $customer,
            status: 'overdue',
            subtotalCents: 77000,
            taxCents: 5000,
            totalCents: 82000,
            issuedAt: CarbonImmutable::parse('2026-02-08 10:41:07'),
            dueAt: CarbonImmutable::parse('2026-03-10 00:00:00'),
            paidAt: null,
            feeCents: 3520,
            notes: null,
            updatedAt: CarbonImmutable::parse('2026-04-20 06:00:01'),
            lines: [$this->pinnedLine('Website copy: 7 pages', 7, 11000)],
            payments: [],
        );
    }

    private function pinnedLine(string $description, int $quantity, int $unitCents): array
    {
        return [
            'description' => $description,
            'quantity' => $quantity,
            'unit_cents' => $unitCents,
            'amount_cents' => $quantity * $unitCents,
        ];
    }

    private function issuedAtFor(int $slot): CarbonImmutable
    {
        $day = $this->dayForSlot($slot);

        return $day->addSeconds($this->rng->int(8 * 3600, 18 * 3600 - 1));
    }

    private function dayForSlot(int $slot): CarbonImmutable
    {
        for ($index = 1; $index < count(self::DATE_ANCHORS); $index++) {
            [$endSlot, $endDate] = self::DATE_ANCHORS[$index];
            [$startSlot, $startDate] = self::DATE_ANCHORS[$index - 1];

            if ($slot > $endSlot) {
                continue;
            }

            $start = CarbonImmutable::parse($startDate);
            $totalDays = (int) $start->diffInDays(CarbonImmutable::parse($endDate));
            $progress = ($slot - $startSlot) / ($endSlot - $startSlot);

            return $start->addDays((int) floor($progress * $totalDays));
        }

        return CarbonImmutable::parse(self::DATE_ANCHORS[array_key_last(self::DATE_ANCHORS)][1]);
    }

    private function buildLines(array $catalog, int $count): array
    {
        $picked = array_slice($this->rng->shuffle($catalog), 0, min($count, count($catalog)));
        $lines = [];

        foreach ($picked as [$description, $minQuantity, $maxQuantity, $minPrice, $maxPrice]) {
            $quantity = $this->rng->int($minQuantity, $maxQuantity);
            $unitCents = max(100, intdiv($this->rng->int($minPrice, $maxPrice), 100) * 100);

            $lines[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unit_cents' => $unitCents,
                'amount_cents' => $quantity * $unitCents,
            ];
        }

        return $lines;
    }

    private function taxCents(array $lines, object $customer): int
    {
        $rate = $this->taxRate($customer);

        return array_sum(array_map(
            fn (array $line) => (int) round($line['amount_cents'] * $rate),
            $lines,
        ));
    }

    private function taxRate(object $customer): float
    {
        if ((int) $customer->id === self::ACME_ID) {
            return 0.0704;
        }

        return match ($customer->country) {
            'DE' => 0.19,
            'GB' => 0.20,
            'CA' => 0.13,
            'AU' => 0.10,
            default => [0.0, 0.0625, 0.07, 0.0825, 0.0][$customer->id % 5],
        };
    }

    private function paidTiming(CarbonImmutable $issuedAt, CarbonImmutable $dueAt, int $totalCents): array
    {
        $delayDays = $this->rng->chance(0.12)
            ? $this->rng->int(31, 75)
            : $this->rng->int(-3, 12);

        $paidAt = $dueAt->addDays($delayDays)->setTime($this->rng->int(8, 17), $this->rng->int(0, 59), $this->rng->int(0, 59));
        $earliest = $issuedAt->addDay();

        if ($paidAt < $earliest) {
            $paidAt = $earliest;
        }

        if ($paidAt > $this->anchor->setTime(6, 0)) {
            $paidAt = $this->anchor->subDays($this->rng->int(0, 3))->setTime($this->rng->int(8, 17), $this->rng->int(0, 59), $this->rng->int(0, 59));
        }

        $daysPastDue = max(0, (int) $dueAt->diffInDays($paidAt, false));

        return [$paidAt, $this->lateFeeCents($totalCents, $daysPastDue)];
    }

    private function lateFeeCents(int $balanceCents, int $daysPastDue): int
    {
        $chargeDays = $daysPastDue - config('ledgerly.late_fee.grace_days');

        if ($chargeDays <= 0) {
            return 0;
        }

        $feeCents = (int) round($balanceCents * config('ledgerly.late_fee.daily_rate') * $chargeDays);

        if ($feeCents < config('ledgerly.late_fee.minimum_cents')) {
            return 0;
        }

        return $feeCents;
    }

    private function overdueFeeAndNotes(int $balanceCents, int $daysPastDue): array
    {
        if ($daysPastDue <= config('ledgerly.late_fee.grace_days')) {
            return [0, null];
        }

        if ($this->rng->chance(0.10)) {
            return [0, 'Disputed by the client. Reminders and late fees are paused until the dispute is resolved.'];
        }

        if ($this->rng->chance(0.08)) {
            return [0, 'On a payment plan agreed with the client. Late fees are paused while instalments are on time.'];
        }

        return [$this->lateFeeCents($balanceCents, $daysPastDue), null];
    }

    private function nightlyUpdate(int $feeCents, CarbonImmutable $dueAt): CarbonImmutable
    {
        if ($feeCents > 0) {
            return $this->anchor->setTime(6, 0, 1);
        }

        return $dueAt->addDay()->setTime(0, 5, 0);
    }

    private function settlementPayments(object $customer, CarbonImmutable $issuedAt, CarbonImmutable $paidAt, int $amountCents): array
    {
        if ($amountCents >= 20000 && $this->rng->chance(0.08)) {
            $firstCents = (int) round($amountCents / 2);
            $firstAt = $paidAt->subDays($this->rng->int(5, 25));

            if ($firstAt < $issuedAt->addDay()) {
                $firstAt = $issuedAt->addDay();
            }

            return [
                $this->payment($customer, $firstCents, $firstAt),
                $this->payment($customer, $amountCents - $firstCents, $paidAt),
            ];
        }

        return [$this->payment($customer, $amountCents, $paidAt)];
    }

    private function partialPayments(object $customer, CarbonImmutable $issuedAt, int $totalCents): array
    {
        if (! $this->rng->chance(0.10)) {
            return [];
        }

        $amountCents = (int) round($totalCents * $this->rng->int(30, 60) / 100);
        $paidAt = $issuedAt->addDays($this->rng->int(1, 20));

        if ($paidAt > $this->anchor) {
            $paidAt = $this->anchor->setTime(9, 30);
        }

        return [$this->payment($customer, $amountCents, $paidAt)];
    }

    private function payment(object $customer, int $amountCents, CarbonImmutable $paidAt): array
    {
        $method = $this->rng->weighted(self::PAYMENT_METHODS);

        if ($method === 'ach' && $customer->currency !== 'USD') {
            $method = 'bank_transfer';
        }

        return [
            'amount_cents' => $amountCents,
            'method' => $method,
            'reference' => strtoupper(substr($method, 0, 3)).'-'.$this->rng->int(100000, 999999),
            'paid_at' => $paidAt,
        ];
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
