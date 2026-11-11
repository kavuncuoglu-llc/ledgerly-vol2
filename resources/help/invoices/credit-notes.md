---
title: Credit notes
category: invoices
summary: Explains what a credit note is, how to issue one and how it differs from a refund or a void.
updated: 2025-05-06
---

A credit note reduces what a client owes on an invoice without cancelling the invoice. Use it for price adjustments, returned goods, goodwill discounts or errors you want to correct on the record.

## Issue a credit note

1. Open a Sent, Overdue or Paid invoice.
2. Choose **More > Issue credit note**.
3. Enter the amount or select specific lines to credit. The credit cannot exceed the invoice total.
4. Add a reason. It is shown to the client.
5. Choose **Issue**.

The credit note gets its own number from the `CN-` sequence, such as `CN-0012`, and a PDF that is emailed to the client if you leave **Email client** ticked.

## What it does to the invoice

- On an unpaid invoice, the balance goes down by the credit. If the balance reaches zero, the invoice becomes Paid.
- Tax is credited in proportion, line by line.
- Late fees are calculated on the new, lower balance from the next nightly run. A fee already added stays unless you waive it (see [Waive or reduce a late fee](/help/billing/waive-or-reduce-a-late-fee)).
- On a Paid invoice, the credit becomes customer credit that can be applied to a future invoice (see [Customer credit balance](/help/customers/customer-credit-balance)).

## Credit note, refund or void

| Goal | Use |
| --- | --- |
| Lower the amount and keep the invoice | Credit note |
| Return money the client already paid | [Refund](/help/payments/refunds), or a credit note if you refund outside Ledgerly |
| Cancel the whole invoice | [Void](/help/invoices/void-an-invoice) |

## Tax reporting

Credit notes appear in the [tax summary report](/help/taxes/tax-summary-report) as negative amounts in the period they are issued, not the period of the original invoice.

## Disputes

When you uphold a dispute, a credit note is the usual outcome. See [Disputing a charge](/help/billing/disputes).
