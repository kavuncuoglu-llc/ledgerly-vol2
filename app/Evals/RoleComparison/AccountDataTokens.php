<?php

namespace App\Evals\RoleComparison;

use App\Ai\Encoding\DataEncoder;
use App\Ai\Shapes\InvoiceSummaryShape;
use App\Ai\Tokens\RatioCounter;
use App\Ai\Tokens\TiktokenCounter;
use App\Ai\Tokens\TokenCounter;
use App\Models\Customer;
use App\Models\Invoice;
use Throwable;

// Chapter 48, Topic 8: the tokens a customer's account data costs in the prompt, for one encoder. The same rows the assistant's list_invoices tool returns (the customer's latest invoices, in the declared six fields), written by each format and counted with the BPE tokenizer the app counts with; when the tokenizer's vocabulary is not on this machine the estimate stands in, and the result says so.
final class AccountDataTokens
{
    public const ROWS = 25;

    public function __construct(private DataEncoder $encoder) {}

    /** @return array{tokens: int, counter: string, rows: int} */
    public function measure(string $format, ?int $customerId = null): array
    {
        $rows = Invoice::query()->forTools()
            ->where('customer_id', $customerId ?? $this->customerId())
            ->latest('issued_at')
            ->limit(self::ROWS)
            ->get();

        $text = $this->encoder->encode($rows, new InvoiceSummaryShape, $format, 'invoices');

        [$counter, $name] = $this->counter($text);

        return ['tokens' => $counter->count($text), 'counter' => $name, 'rows' => $rows->count()];
    }

    private function customerId(): int
    {
        return (int) (Customer::query()->where('name', 'Acme Studios')->value('id') ?? Invoice::query()->value('customer_id') ?? 0);
    }

    /** @return array{0: TokenCounter, 1: string} */
    private function counter(string $sample): array
    {
        try {
            $counter = new TiktokenCounter;

            $counter->count($sample);

            return [$counter, 'o200k_base'];
        } catch (Throwable) {
            return [new RatioCounter, 'estimate (characters / 4)'];
        }
    }
}
