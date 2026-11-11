---
title: Mark an invoice as paid
category: invoices
summary: Shows how to record a payment you received outside Ledgerly so the invoice moves to Paid.
updated: 2025-09-29
---

Payments made through the Ledgerly pay link update the invoice automatically. When a client pays you some other way, such as a bank transfer, cash or cheque, you record the payment yourself.

## Steps

1. Open the invoice.
2. Choose **Record payment**.
3. Enter the **Amount**. It defaults to the full outstanding balance.
4. Set the **Date received**. This can be in the past.
5. Choose the **Method**: bank transfer, cash, cheque or other.
6. Add a reference such as a transaction number (optional).
7. Select **Save payment**.

If the amount equals the balance, the status becomes Paid and reminders and late fees stop. If the amount is less, the invoice shows a Partial badge and keeps its current status. See [Partial payments](/help/payments/partial-payments).

## Backdating and late fees

The date received matters. If a client actually paid on day 25 past due but you record it on day 40, set the date received to day 25 and Ledgerly removes any fee added for the days after the payment. Fees are only charged on days when a balance was outstanding (see [Late fees](/help/billing/late-fees)).

## A shortcut

Choose **Mark as paid** from the invoice menu to record the full balance with today's date and the method set to Other. Use it only when the exact details do not matter.

## Receipts

Ledgerly emails a receipt to the client when you record a payment, unless you clear **Email receipt**. See [Payment receipts](/help/payments/payment-receipts).

## Mistakes

Open the payment under **Payments** on the invoice and choose **Delete payment** to undo it. The invoice goes back to Sent or Overdue and the late fee clock catches up at the next nightly run. For card payments that you want to return, use [Refunds](/help/payments/refunds).

For payments recorded in bulk, see [Record an offline payment](/help/payments/record-an-offline-payment).
