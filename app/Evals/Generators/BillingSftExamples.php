<?php

namespace App\Evals\Generators;

use App\Evals\EvalAnswerer;
use App\Evals\GoldenCase;
use App\Models\Invoice;
use App\Support\Ledgerly;
use Illuminate\Support\Collection;

// Chapter 39, Topic 9: 200 synthetic billing examples for the first LoRA run, in the prompt-and-completion format, and 20 held-out golden cases. Like Chapter 29's generators the facts come from the seeded database and the wording from templates, so the same database gives the same files. Held-out invoices never appear in the training rows.
final class BillingSftExamples
{
    public const VERSION = 1;

    public const SYSTEM = 'You are Ledgerly\'s billing support assistant. Answer from the account data and sources you are given. Always name the invoice and state the amount. Cite the source as [label]. If the sources do not cover the question, say so and offer to open a ticket.';

    public const LATE_FEE_LABEL = 'help:billing/late-fees#how-the-fee-is-calculated';

    public const LATE_FEE_SOURCE = 'The fee is 1.5% of the outstanding balance per month, prorated by day. It is calculated on the invoice total excluding any previous late fees, so fees never compound.';

    private const LATE_FEE_QUESTIONS = [
        'Why is there a late fee on {number}?',
        'I got charged a late fee on {number}, what is it for?',
        'Can you explain the extra charge on invoice {number}? It says late fee.',
        'what is this late fee on {number}',
        'My client asked why {number} has a late fee added. What do I tell them?',
        'How did {number} end up with a late fee?',
    ];

    private const AMOUNT_DUE_QUESTIONS = [
        'How much is still due on {number}?',
        'what do I owe on {number}',
        'Can you tell me the balance for invoice {number}?',
        'When is {number} due and how much is it?',
        'Quick check: what is outstanding on {number}?',
    ];

    private const PAYMENT_QUESTIONS = [
        'Did my payment for {number} go through?',
        'Is {number} marked as paid?',
        'I paid {number} last month, can you confirm it was received?',
        'What is the status of {number}?',
    ];

    public function __construct(private int $heldOut = 20) {}

    /** @return list<array{prompt: list<array>, completion: list<array>}> */
    public function rows(int $count): array
    {
        $late = $this->lateFeeInvoices();

        $trainingLate = $late->slice($this->heldOut)->values();

        $perKind = intdiv($count, 3);

        $lateCount = min($trainingLate->count(), $count - 2 * $perKind);

        $rows = [
            ...$trainingLate->take($lateCount)->map(fn (Invoice $invoice) => $this->lateFeeRow($invoice))->all(),
            ...$this->openInvoices()->take($perKind)->map(fn (Invoice $invoice) => $this->amountDueRow($invoice))->all(),
            ...$this->paidInvoices()->take($count - $lateCount - $perKind)->map(fn (Invoice $invoice) => $this->paymentRow($invoice))->all(),
        ];

        usort($rows, fn (array $a, array $b) => crc32($a['prompt'][1]['content']) <=> crc32($b['prompt'][1]['content']));

        return $rows;
    }

    /** @return list<array> golden cases as arrays, each with the `prompt` the training rows use, so quick_eval.py asks them the way the model was trained */
    public function heldOut(): array
    {
        return $this->lateFeeInvoices()->take($this->heldOut)->map(fn (Invoice $invoice) => $this->heldOutCase($invoice)->toArray() + ['prompt' => $this->lateFeeRow($invoice)['prompt']])->all();
    }

    private function heldOutCase(Invoice $invoice): GoldenCase
    {
        return new GoldenCase(
            id: "synthetic-holdout-{$invoice->number}",
            category: 'billing',
            source: 'synthetic',
            customer: EvalAnswerer::fixtureKey($invoice->customer),
            question: $this->question(self::LATE_FEE_QUESTIONS, $invoice),
            expectedBehavior: 'answer',
            expectedChunks: [self::LATE_FEE_LABEL],
            mustInclude: [$invoice->number, number_format($invoice->lateFee(), 2)],
            mustNotInclude: $this->otherNumbers($invoice),
            referenceAnswer: $this->lateFeeAnswer($invoice),
            generator: 'billing-sft@'.self::VERSION,
        );
    }

    private function lateFeeInvoices(): Collection
    {
        return $this->fixtures()
            ->withLateFee()
            ->get()
            ->sortBy(fn (Invoice $invoice) => crc32('billing-sft-late:'.$invoice->number))
            ->values();
    }

    private function openInvoices(): Collection
    {
        return $this->fixtures()
            ->whereIn('status', ['sent', 'overdue'])
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->balanceDue() > 0)
            ->reject(fn (Invoice $invoice) => in_array($invoice->number, $this->heldOutNumbers(), true))
            ->sortBy(fn (Invoice $invoice) => crc32('billing-sft-open:'.$invoice->number))
            ->values();
    }

    /** @return list<string> */
    private function heldOutNumbers(): array
    {
        return $this->lateFeeInvoices()->take($this->heldOut)->pluck('number')->all();
    }

    private function paidInvoices(): Collection
    {
        return $this->fixtures()
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('late_fee_cents', 0)
            ->get()
            ->sortBy(fn (Invoice $invoice) => crc32('billing-sft-paid:'.$invoice->number))
            ->values();
    }

    private function fixtures()
    {
        return Invoice::query()
            ->where('customer_id', '<=', (int) config('ai.eval.fixture_customers', 40))
            ->with(['customer', 'payments'])
            ->orderBy('id');
    }

    private function lateFeeRow(Invoice $invoice): array
    {
        return $this->row(
            question: $this->question(self::LATE_FEE_QUESTIONS, $invoice),
            account: [
                'invoice' => $invoice->number,
                'status' => $invoice->status->value,
                'total' => number_format((float) $invoice->total, 2),
                'due_date' => $invoice->due_at->toDateString(),
                'days_late' => $this->daysLate($invoice),
                'late_fee' => number_format($invoice->lateFee(), 2),
            ],
            sources: [self::LATE_FEE_LABEL => self::LATE_FEE_SOURCE],
            answer: $this->lateFeeAnswer($invoice),
        );
    }

    private function lateFeeAnswer(Invoice $invoice): string
    {
        return sprintf(
            'Invoice %s was %d days past its due date of %s, so a late fee of $%s was added to its total of $%s. Late fees are 1.5%% of the balance per month, prorated by day [%s].',
            $invoice->number,
            $this->daysLate($invoice),
            $invoice->due_at->format('F j, Y'),
            number_format($invoice->lateFee(), 2),
            number_format((float) $invoice->total, 2),
            self::LATE_FEE_LABEL,
        );
    }

    private function amountDueRow(Invoice $invoice): array
    {
        $balance = number_format($invoice->balanceDue(), 2);

        return $this->row(
            question: $this->question(self::AMOUNT_DUE_QUESTIONS, $invoice),
            account: [
                'invoice' => $invoice->number,
                'status' => $invoice->status->value,
                'total' => number_format((float) $invoice->total, 2),
                'due_date' => $invoice->due_at->toDateString(),
                'balance_due' => $balance,
            ],
            sources: [],
            answer: sprintf('Invoice %s is %s with $%s still due. Its due date is %s.', $invoice->number, $invoice->status->value, $balance, $invoice->due_at->format('F j, Y')),
        );
    }

    private function paymentRow(Invoice $invoice): array
    {
        $total = number_format((float) $invoice->total, 2);

        return $this->row(
            question: $this->question(self::PAYMENT_QUESTIONS, $invoice),
            account: [
                'invoice' => $invoice->number,
                'status' => 'paid',
                'total' => $total,
                'paid_on' => $invoice->paid_at->toDateString(),
            ],
            sources: [],
            answer: sprintf('Yes, invoice %s is paid. The full $%s was received on %s, so nothing is due.', $invoice->number, $total, $invoice->paid_at->format('F j, Y')),
        );
    }

    private function row(string $question, array $account, array $sources, string $answer): array
    {
        $content = "Question: {$question}\n\nAccount data:\n".json_encode($account, JSON_UNESCAPED_SLASHES);

        foreach ($sources as $label => $text) {
            $content .= "\n\nSource [{$label}]:\n{$text}";
        }

        return [
            'prompt' => [
                ['role' => 'system', 'content' => self::SYSTEM],
                ['role' => 'user', 'content' => $content],
            ],
            'completion' => [
                ['role' => 'assistant', 'content' => $answer],
            ],
        ];
    }

    private function question(array $templates, Invoice $invoice): string
    {
        $template = $templates[crc32($invoice->number) % count($templates)];

        return str_replace('{number}', $invoice->number, $template);
    }

    private function otherNumbers(Invoice $invoice): array
    {
        return Invoice::query()
            ->where('customer_id', '!=', $invoice->customer_id)
            ->orderBy('id')
            ->limit(3)
            ->pluck('number')
            ->all();
    }

    private function daysLate(Invoice $invoice): int
    {
        $until = $invoice->paid_at ?? Ledgerly::today();

        return max(1, (int) $invoice->due_at->copy()->startOfDay()->diffInDays($until->copy()->startOfDay(), false));
    }
}
