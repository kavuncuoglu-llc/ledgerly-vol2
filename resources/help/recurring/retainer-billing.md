---
title: Retainer billing
category: recurring
summary: Describes a typical setup for billing a client a fixed monthly retainer with recurring invoices.
updated: 2024-08-14
---

A retainer is a fixed amount a client pays each period for access to your time or services. Ledgerly handles it with a monthly recurring invoice.

## Recommended setup

1. Create an invoice for the retainer amount with one line, for example "Monthly retainer, {{month}} {{year}}".
2. Set the **Payment terms**. Retainers are often billed in advance, so Due on receipt or Net 7 is common (see [Payment terms](/help/invoices/payment-terms)).
3. Choose **Make recurring** with a **Monthly** interval and a start date on the first of the month.
4. Leave **Send automatically** on for a fixed amount.
5. Keep **Charge late fee** on if your agreement allows it.

The setup steps are the same as in [Recurring invoices](/help/recurring/recurring).

## Adding extras

If a client uses more hours than the retainer includes, create a separate manual invoice for the overage, rather than editing the template. That keeps the recurring amount constant and the extras easy to see. You can duplicate last month's overage invoice to save time (see [Duplicate an invoice](/help/invoices/duplicate-an-invoice)).

## Raising the price

Edit the line item on the template and save before the next run. Past invoices are not changed (see [Edit a recurring template](/help/recurring/edit-a-recurring-template)).

## Billing in advance and late payment

If you bill on the first and the invoice is due on receipt, it becomes Overdue on the second. Reminders arrive 3, 7 and 14 days later, and a late fee is added 30 days after the due date. This surprises some owners who expected a fee sooner. See [Late fees](/help/billing/late-fees) for the timing, and [Late fee settings](/help/billing/late-fee-settings) if you need a different policy.

## Pausing a retainer

If a client pauses the engagement, pause the schedule so no invoices are created (see [Pause or end a recurring invoice](/help/recurring/pause-or-end-a-recurring-invoice)).

## Taxes

If the retainer is taxable, set the tax rate on the line, and it is copied each month. See [Add tax rates](/help/taxes/add-tax-rates).
