---
title: Unexpected late fee
category: troubleshooting
summary: Helps you work out why a late fee appeared on an invoice, or why one did not, and what you can do about it.
updated: 2025-10-11
---

A late fee follows fixed rules, so you can check an invoice against them. Open the invoice and compare it with the list below.

## Why a fee was added

A fee is added automatically when all of these are true:

1. The invoice has a balance and its status is Sent or Overdue.
2. It is 30 or more days past its due date.
3. **Charge late fee** is on for the invoice, the customer and the workspace.
4. It is not marked Disputed and has no active payment plan.

Then the fee is 1.5% of the outstanding balance per month, prorated by day, calculated without earlier fees (see [Late fees](/help/billing/late-fees)). Check the **Activity** tab, which shows the date the first fee was added and the days it covered.

## Why a fee was not added

- The invoice is less than 30 days past due. Overdue status and reminders come earlier.
- The fee would have been less than 1.00 in the invoice currency.
- The invoice was Disputed or on a payment plan during those days.
- Late fees are off in the workspace, for the customer or for the invoice (see [Late fee settings](/help/billing/late-fee-settings)).
- It is a Draft, Paid or Void invoice.

## Why the fee is larger than expected

- It keeps growing nightly after day 30 until the invoice is paid, 0.05% of the balance per day.
- The balance may be higher than the original total if the customer made only a partial payment (see [Partial payments](/help/payments/partial-payments)).
- The due date may be earlier than you thought. Check the payment terms and issue date (see [Due date and issue date](/help/invoices/due-date-vs-issue-date)).

## Why a fee is on a recurring invoice

Each generated invoice has its own clock (see [Late fees on recurring invoices](/help/recurring/late-fees-on-recurring-invoices)).

## What you can do

- Remove or reduce it yourself (see [Waive or reduce a late fee](/help/billing/waive-or-reduce-a-late-fee)).
- If the client paid on time, record the payment with the correct date received and the fee for later days disappears (see [Mark an invoice as paid](/help/invoices/mark-invoice-as-paid)).
- If the client disagrees, mark the invoice as disputed (see [Disputing a charge](/help/billing/disputes)).

Ledgerly support cannot remove fees from your clients' invoices.
