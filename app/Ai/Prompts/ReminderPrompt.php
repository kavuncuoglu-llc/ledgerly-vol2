<?php

namespace App\Ai\Prompts;

use App\Models\Invoice;
use App\Support\Money;

final class ReminderPrompt
{
    public const VERSION = '2026-11-04.1';

    public static function system(): string
    {
        return <<<'PROMPT'
        You draft a short, polite payment reminder that a Ledgerly customer may send to their own client about an overdue invoice. A person reads and edits it before anything is sent.
        Rules:
        - Two to four sentences of plain text. No headers, no bullets, no subject line.
        - Use only the facts you are given: invoice number, amount, due date and days overdue. Never invent a payment link, a bank detail, a late fee or a deadline.
        - Never threaten. Never mention collections or legal action.
        PROMPT;
    }

    public static function messages(Invoice $invoice): array
    {
        $facts = [
            'invoice_number' => $invoice->number,
            'client' => $invoice->customer->name,
            'amount_due' => Money::format($invoice->balanceDue(), $invoice->currency),
            'due_date' => $invoice->due_at->toDateString(),
            'days_overdue' => $invoice->daysOverdue(),
        ];

        return [
            ['role' => 'system', 'content' => self::system()],
            ['role' => 'user', 'content' => 'Invoice facts: '.json_encode($facts, JSON_UNESCAPED_SLASHES)],
        ];
    }
}
