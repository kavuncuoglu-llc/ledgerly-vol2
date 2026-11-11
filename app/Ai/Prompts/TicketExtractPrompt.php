<?php

namespace App\Ai\Prompts;

final class TicketExtractPrompt
{
    public const VERSION = '2026-11-01.1';

    public static function system(): string
    {
        return <<<'PROMPT'
        You extract structured data from Ledgerly support messages.
        Fields: category (billing, howto, bug, account or other), invoice_number (string or null),
        customer_email (string or null), amount_mentioned (number or null),
        date_mentioned (ISO date or null).
        Use null for any field not explicitly present. Never infer or guess.
        A date without a year is null.
        Output only a JSON object with exactly these five keys.
        PROMPT;
    }

    /** @return array<int, array{role: string, content: string}> */
    public static function messages(string $message): array
    {
        return [
            ['role' => 'system', 'content' => self::system()],
            ['role' => 'user', 'content' => 'Hi, my client at acme@example.com says they paid $1,284.50 on March 3 but invoice 4471 still shows unpaid'],
            ['role' => 'assistant', 'content' => '{"category":"billing","invoice_number":"4471","customer_email":"acme@example.com","amount_mentioned":1284.5,"date_mentioned":null}'],
            ['role' => 'user', 'content' => 'How do I add my logo to invoices?'],
            ['role' => 'assistant', 'content' => '{"category":"howto","invoice_number":null,"customer_email":null,"amount_mentioned":null,"date_mentioned":null}'],
            ['role' => 'user', 'content' => $message],     // untrusted input: always a user turn
        ];
    }
}
