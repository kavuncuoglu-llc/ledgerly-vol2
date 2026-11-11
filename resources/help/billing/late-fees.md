---
title: Late fees
category: billing
summary: Explains what Ledgerly charges for late invoices, when the charge is added and how it is calculated.
updated: 2025-09-12
---

A late fee is an extra charge added to an invoice when a client pays after the due date. Ledgerly adds it for you, so you do not have to calculate or type it in. Late fees are on by default for every invoice on every plan.

## When a late fee is applied

A late fee is applied automatically 30 days after the due date. Those 30 days are the grace period: if the invoice is paid in full on or before day 30 past due, no fee is ever added. The invoice turns Overdue the day after the due date, but that alone does not trigger a fee (see [Invoice statuses](/help/invoices/statuses)).

No fee is applied while either of these is true:

- The invoice is marked Disputed. See [Disputing a charge](/help/billing/disputes).
- A payment plan is active on the invoice. See [Offering a payment plan](/help/payments/payment-plans).

Days spent in a dispute or a plan are not charged. Ledgerly does not waive a first late fee automatically, but you can remove or reduce any fee yourself (see [Waive or reduce a late fee](/help/billing/waive-or-reduce-a-late-fee)). Draft, Void and Paid invoices never receive fees.

## How the fee is calculated

The fee is 1.5% of the outstanding balance per month, prorated by day. It is calculated on the invoice total excluding any previous late fees, so fees never compound.

For partial months, the daily rate is the monthly rate divided by 30, which is 0.05% of the balance per day. A fee that would be less than 1.00 in the invoice currency is not charged. In practice that means an invoice with a balance under 66.67 does not pick up a fee in its first 30 days past due.

At day 30 Ledgerly adds the days built up since the due date, then posts one more day each night until the invoice is paid. Payments reduce the balance used for later days.

### Example

An invoice for $1,000.00 is due June 1 and is still unpaid on July 1 (day 30). Ledgerly adds a late fee of $15.00 (30 days at 0.05% of $1,000.00). If the client pays on July 11, day 40, the fee is $20.00 and the invoice shows a total of $1,020.00.

## What your client sees

The fee appears as a separate Late fee line, and the client gets one email when it is first added. Late fees are not taxed (see [Tax on late fees](/help/taxes/tax-on-late-fees)).

## Changing the rules

Starter and Pro use a fixed 1.5% rate and can switch fees on or off. Enterprise can set a custom rate and grace period. See [Late fee settings](/help/billing/late-fee-settings) and [Late fees on recurring invoices](/help/recurring/late-fees-on-recurring-invoices).
