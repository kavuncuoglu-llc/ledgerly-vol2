---
title: ACH bank transfers
category: payments
summary: Describes how clients pay by ACH bank transfer, how long it takes and what can go wrong.
updated: 2025-02-19
---

ACH lets a client in the United States pay directly from a bank account. It costs less than cards, which makes it a good fit for large invoices.

## Requirements

- LedgerlyPay must be active (see [Accepting card payments](/help/payments/accepting-card-payments)).
- The invoice must be in US dollars. ACH is not available in other currencies.
- The client needs a US checking or savings account.

Switch on **Bank transfer (ACH)** under **Settings > Payments**. It then appears next to Card on the pay page.

## How the client pays

The client chooses **Bank transfer** and verifies their account by signing in to their bank through a secure connection, or by confirming two small deposits, which takes 1 to 2 business days. After they confirm, the invoice shows a Processing badge.

## How long it takes

ACH takes 3 to 5 business days to clear. During that time the invoice stays Sent or Overdue, and reminders and late fees keep running because no money has been received yet. If the client's payment clears, Ledgerly marks the invoice Paid and sets the payment date to the day the client submitted it, so days in processing are not charged as late fees.

This is a common reason for "my client says they paid but it still shows unpaid". See [Client says they paid but the invoice is unpaid](/help/troubleshooting/client-says-paid-but-unpaid).

## Fees

0.8% of the payment, capped at $5.00. See [Payment processing fees](/help/payments/payment-processing-fees).

## Returned payments

If the bank rejects the transfer (insufficient funds, closed account), the payment fails and the invoice goes back to its previous state. You and the client are notified. A returned ACH payment has an additional $4.00 fee. See [Failed payments](/help/payments/failed-payments).

## Refunds

ACH payments can be refunded within 90 days. The money returns in 5 to 10 business days (see [Refunds](/help/payments/refunds)).
