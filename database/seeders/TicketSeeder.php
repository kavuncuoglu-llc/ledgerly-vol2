<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\ResetsSequences;
use Database\Seeders\Support\SeededRandom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TicketSeeder extends Seeder
{
    use ResetsSequences;

    private const SEED = 10401;

    private const FIRST_ID = 10401;

    public function run(): void
    {
        $rng = new SeededRandom(self::SEED);
        $entries = require __DIR__.'/data/tickets.php';
        $contacts = array_map(fn (array $row) => $row[1], require __DIR__.'/data/customers.php');
        $pinnedIds = array_filter(array_column($entries, 'id'));
        $invoicesByKind = $this->invoicesByKind();
        $nextId = self::FIRST_ID;
        $rows = [];

        foreach ($entries as $entry) {
            $id = $entry['id'] ?? $this->nextFreeId($nextId, $pinnedIds);
            [$customerId, $invoiceNumber] = $this->resolveSubject($entry, $invoicesByKind, $rng);
            $placeholders = $this->placeholders($contacts[$customerId], $invoiceNumber);
            $createdAt = CarbonImmutable::parse($entry['at'].':'.str_pad((string) $rng->int(0, 59), 2, '0', STR_PAD_LEFT));

            $rows[] = [
                'id' => $id,
                'customer_id' => $customerId,
                'user_id' => $customerId,
                'subject' => strtr($entry['subject'], $placeholders),
                'body' => strtr($entry['body'], $placeholders),
                'summary' => null,
                'category' => $entry['category'],
                'status' => $entry['status'],
                'source' => $entry['source'],
                'invoice_number' => $invoiceNumber,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'updated_at' => $this->updatedAt($createdAt, $entry['status'], $rng)->format('Y-m-d H:i:s'),
            ];
        }

        DB::table('tickets')->insert($rows);

        $this->resetSequence('tickets');
    }

    private function nextFreeId(int &$nextId, array $pinnedIds): int
    {
        while (in_array($nextId, $pinnedIds, true)) {
            $nextId++;
        }

        return $nextId++;
    }

    private function invoicesByKind(): array
    {
        $invoices = DB::table('invoices')->orderBy('id')->get(['number', 'customer_id', 'status', 'late_fee_cents']);

        return [
            'overdue' => $invoices->where('status', 'overdue')->values()->all(),
            'fee' => $invoices->where('late_fee_cents', '>', 0)->values()->all(),
            'paid' => $invoices->where('status', 'paid')->values()->all(),
            'sent' => $invoices->where('status', 'sent')->values()->all(),
        ];
    }

    private function resolveSubject(array $entry, array $invoicesByKind, SeededRandom $rng): array
    {
        if (isset($entry['customer'])) {
            return [$entry['customer'], $entry['invoice_number'] ?? null];
        }

        $kind = $entry['invoice'] ?? null;

        if ($kind === null) {
            return [$rng->int(1, 40), null];
        }

        $invoice = $rng->pick($invoicesByKind[$kind]);

        return [(int) $invoice->customer_id, $invoice->number];
    }

    private function placeholders(string $contact, ?string $invoiceNumber): array
    {
        return [
            '{contact}' => $contact,
            '{invoice}' => $invoiceNumber ?? 'the invoice',
            '{invoice_digits}' => $invoiceNumber
                ? substr($invoiceNumber, 4)
                : '',
        ];
    }

    private function updatedAt(CarbonImmutable $createdAt, string $status, SeededRandom $rng): CarbonImmutable
    {
        if ($status === 'open') {
            return $createdAt;
        }

        return $createdAt->addMinutes($rng->int(25, 600));
    }
}
