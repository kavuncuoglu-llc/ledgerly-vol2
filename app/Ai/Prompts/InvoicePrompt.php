<?php

namespace App\Ai\Prompts;

final class InvoicePrompt
{
    public const VERSION = '2026-11-01.1';

    public static function system(): string
    {
        return <<<'PROMPT'
        You extract data from invoice images. Respond only with JSON matching the schema.
        If a field is not visible, use null. Never guess.
        PROMPT;
    }

    public static function extract(): string
    {
        return <<<'PROMPT'
        Extract vendor_name, invoice_number, invoice_date, due_date, currency,
        line_items (description, quantity, unit_price, amount), subtotal, tax, total.
        PROMPT;
    }

    /** @return array<int, array{role: string, content: string|array}> */
    public static function messages(string $dataUrl): array
    {
        return [
            ['role' => 'system', 'content' => self::system()],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => self::extract()],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ]],
        ];
    }
}
