<?php

namespace App\Evals\Generators;

use App\Evals\EvalAnswerer;
use App\Evals\GoldenCase;
use App\Models\Invoice;

// Chapter 29, Figure 29-7: the facts come from the database, not a model. The model only writes how the customer would put it.
final class LateInvoiceCases implements Generator
{
    public const VERSION = 1;

    public function __construct(private QuestionWriter $writer) {}

    public function name(): string
    {
        return 'late-invoices';
    }

    public function generate(int $count): iterable
    {
        $invoices = Invoice::query()
            ->withLateFee()
            ->where('customer_id', '<=', (int) config('ai.eval.fixture_customers', 40))
            ->with('customer')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Invoice $invoice) => crc32('late-invoices:'.$invoice->number))        // a stable shuffle: the same database gives the same cases
            ->take($count);

        foreach ($invoices as $invoice) {
            yield new GoldenCase(
                id: "synthetic-late-{$invoice->number}",
                category: 'billing',
                source: 'synthetic',
                customer: EvalAnswerer::fixtureKey($invoice->customer),
                question: $this->askAs($invoice),                                   // the model writes the wording only
                expectedBehavior: 'answer',
                expectedChunks: ['help:billing/late-fees#how-the-fee-is-calculated'],
                mustInclude: [$invoice->number, number_format($invoice->lateFee(), 2)],      // computed, so it is right
                mustNotInclude: $this->otherNumbers($invoice),
                referenceAnswer: "Invoice {$invoice->number} carries a late fee of ".number_format($invoice->lateFee(), 2).', calculated at 1.5% of the balance per month as described in Late fees.',
                generator: $this->name().'@'.self::VERSION.' via '.$this->writer->provider(),
            );
        }
    }

    private function askAs(Invoice $invoice): string
    {
        return $this->writer->ask(
            'The customer has noticed a late fee on one of their invoices and wants to know why. Mention the invoice number.',
            "Invoice number: {$invoice->number}\nInvoice total: ".number_format((float) $invoice->total, 2)." {$invoice->currency}\nStatus: {$invoice->status->value}",
        );
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
}
