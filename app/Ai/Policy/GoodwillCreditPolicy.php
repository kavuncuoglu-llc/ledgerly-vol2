<?php

namespace App\Ai\Policy;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

// Chapter 51, Topic 3: who gets a goodwill credit, and how much, decided by rules and never by the model. The inputs are the invoice and the customer's own credit history: no name, region, language or plan tier,
// so the counterfactual fairness suite has nothing here to find. The model calls the tool that reads this and explains the answer; IssueCredit asks the same policy again before it asks anyone to approve.
final class GoodwillCreditPolicy
{
    public function decide(?Invoice $invoice, int $customerId): CreditDecision
    {
        if ($invoice === null) {
            return new CreditDecision(false, 0, 'invoice_not_found', 'That invoice is not on this account, so no credit can be considered for it.');
        }

        $lateFee = (int) $invoice->late_fee_cents;

        if ($lateFee <= 0) {
            return new CreditDecision(false, 0, 'no_late_fee', 'Goodwill credits only cover a late fee, and this invoice has none.');
        }

        $window = $this->windowDays();

        if ($this->creditsInWindow($customerId, $window) >= $this->maxPerWindow()) {
            return new CreditDecision(false, 0, 'recent_credit', "A goodwill credit was already given on this account in the last {$window} days, so another cannot be requested yet.");
        }

        $max = min($lateFee, $this->maxCents());

        return new CreditDecision(true, $max, 'late_fee_within_limit', sprintf('A credit of up to $%s can be requested: the late fee on this invoice, capped at $%s. A person approves it.', number_format($max / 100, 2), number_format($this->maxCents() / 100, 2)));
    }

    public function creditsInWindow(int $customerId, int $days): int
    {
        return DB::table('invoice_credits')
            ->where('customer_id', $customerId)
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }

    private function maxCents(): int
    {
        return (int) config('ai.goodwill.max_cents', 5000);
    }

    private function windowDays(): int
    {
        return (int) config('ai.goodwill.window_days', 90);
    }

    private function maxPerWindow(): int
    {
        return (int) config('ai.goodwill.max_per_window', 1);
    }
}
