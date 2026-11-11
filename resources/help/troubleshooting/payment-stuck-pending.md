---
title: Payment stuck pending
category: troubleshooting
summary: Explains what a Processing or Pending payment means and how long to wait before contacting support.
updated: 2025-09-10
---

A payment shows **Processing** or **Pending** when the client has submitted it but the money has not been confirmed yet. How long that lasts depends on the payment type.

## Expected waiting times

| Type | Typical time | When to worry |
| --- | --- | --- |
| Card | Seconds to a minute | After 10 minutes |
| ACH bank transfer | 3 to 5 business days | After 7 business days |
| Card payment under review | Up to 1 business day | After 2 business days |
| Payout to your bank | 2 business days after capture | After 5 business days |

## What the invoice does meanwhile

The invoice keeps its previous status. If it is Overdue it stays Overdue, and reminders and late fees continue. When the payment clears, Ledgerly uses the day the client submitted it as the payment date, so fees for the processing days are removed at the next nightly run (see [Late fees](/help/billing/late-fees)).

If the payment fails, the invoice stays unpaid and you are notified (see [Failed payments](/help/payments/failed-payments)).

## ACH verification

Bank accounts verified by two small deposits can add 1 to 2 business days before the transfer starts. Ask the client whether they confirmed the amounts (see [ACH bank transfers](/help/payments/ach-bank-transfers)).

## Payout is pending, not the payment

If the invoice is Paid but the money is not in your bank, the payment is complete and the payout is in progress. Check **Payments > Payouts** (see [Payout schedule](/help/payments/payout-schedule)). Payouts can be held for incomplete identity checks or an open chargeback (see [Chargebacks](/help/payments/chargebacks)).

## Under review

Card payments that look unusual are held briefly for review. Ledgerly emails you if more information is needed.

## When to contact support

Contact support if the time in the table has passed. Send the invoice number, the payment date and amount and, if the client has it, the bank reference (see [Contact support](/help/troubleshooting/contact-support)).

## If the client says they paid

See [Client says they paid but the invoice is unpaid](/help/troubleshooting/client-says-paid-but-unpaid).
