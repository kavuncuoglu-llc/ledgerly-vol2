<?php

namespace App\Ai\Triage;

use App\Ai\Schemas\Category;
use App\Ai\Schemas\TicketData;
use App\Models\Customer;

// Plain PHP, no model: the same ticket is always urgent or not for the same reason, and the reason can be read.
final class UrgencyRule
{
    private const WORDS = '/\b(urgent(ly)?|asap|immediately|right now|locked out|can\'?t log in|cannot log in|charged twice|double[- ]charged|deadline)\b/i';

    public const OVERDUE_DAYS = 30;

    public static function evaluate(Category $category, ?TicketData $fields, Customer $customer, string $text = ''): Urgency
    {
        return self::reason($category, $fields, $customer, $text) === null
            ? Urgency::Normal
            : Urgency::Urgent;
    }

    public static function reason(Category $category, ?TicketData $fields, Customer $customer, string $text = ''): ?string
    {
        if (preg_match(self::WORDS, $text) === 1) {
            return 'the customer says it is urgent';
        }

        if ($category === Category::Bug && $customer->plan === 'enterprise') {
            return 'a bug on an enterprise account';
        }

        if ($category === Category::Billing && self::invoiceIsLongOverdue($fields, $customer)) {
            return 'the invoice is '.self::OVERDUE_DAYS.' or more days overdue';
        }

        return null;
    }

    private static function invoiceIsLongOverdue(?TicketData $fields, Customer $customer): bool
    {
        if (! $fields?->invoice_number) {
            return false;
        }

        $invoice = $customer->invoices()->whereNumber($fields->invoice_number)->first();      // the customer's own invoices only

        return $invoice !== null && $invoice->daysOverdue() >= self::OVERDUE_DAYS;              // counted from Ledgerly::today()
    }
}
