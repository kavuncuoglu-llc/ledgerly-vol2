---
title: Bank feeds
category: integrations
summary: Explains how PayLane bank feeds suggest matches between bank deposits and open invoices.
updated: 2025-02-03
---

A bank feed brings your bank transactions into Ledgerly and suggests which invoice each deposit pays. It saves you from recording payments by hand. Bank feeds use the PayLane connector and are available on Pro and Enterprise.

## Connect your bank

1. Go to **Settings > Integrations > PayLane** and choose **Connect bank**.
2. Search for your bank and sign in on its page. Ledgerly never sees your banking password.
3. Select the accounts to include.

The connection is read-only. Ledgerly cannot move money. It is renewed every 90 days with a short re-approval at your bank, and an email reminds you a week before it expires.

## How matching works

New deposits appear under **Payments > Bank feed** within a few hours. For each deposit, Ledgerly looks for an open invoice with:

- A reference that includes the invoice number, for example INV-4471
- Or the same amount and a customer name that is similar to the payer

Matches have a confidence label. **High** means a reference matched. **Medium** means amount and name matched. **Low** means only the amount matched.

## Accept a match

Choose **Accept** and Ledgerly records an offline payment with the bank date as the date received (see [Record an offline payment](/help/payments/record-an-offline-payment)). The invoice becomes Paid if the amount equals the balance, and reminders and late fees stop. Because the date received is the bank date, late fees for the days after it are not charged.

Choose **Not this one** to dismiss it, or pick a different invoice.

## Why it helps with "I already paid"

When a client says they paid but the invoice still shows unpaid, the bank feed often has the deposit waiting for you to accept (see [Client says they paid but the invoice is unpaid](/help/troubleshooting/client-says-paid-but-unpaid)).

## Limits

- Transactions from the last 90 days are imported when you connect.
- Only credits (deposits) are shown.
- Deposits that LedgerlyPay made are recognized and not matched again.

## Disconnect

Disconnect at any time. Imported transactions are removed from Ledgerly after 30 days.
