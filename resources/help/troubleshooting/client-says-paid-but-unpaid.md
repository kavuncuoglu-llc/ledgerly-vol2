---
title: Client says they paid but the invoice is unpaid
category: troubleshooting
summary: Walks through the checks to run when a client says they paid but Ledgerly still shows the invoice as unpaid.
updated: 2025-10-08
---

This is the most common support question. In nearly all cases, the money is on its way or was never connected to the invoice. Work through these checks in order.

## 1. How did they pay?

**Through the pay link (card).** The invoice updates within a minute of a successful payment. Open **Payments > Failed** to see whether the attempt was declined (see [Failed payments](/help/payments/failed-payments)). If the client has a bank confirmation and nothing appears on either list, contact support.

**Through the pay link (ACH).** A bank transfer takes 3 to 5 business days to clear and shows a Processing badge meanwhile (see [ACH bank transfers](/help/payments/ach-bank-transfers)). Wait for it to finish.

**Outside Ledgerly (bank transfer, cheque or cash).** Ledgerly cannot know. Record the payment yourself (see [Mark an invoice as paid](/help/invoices/mark-invoice-as-paid)).

## 2. Is the deposit already in your bank feed?

If you use bank feeds, open **Payments > Bank feed**. The deposit may be waiting to be accepted (see [Bank feeds](/help/integrations/bank-feeds)).

## 3. Was it a partial payment?

Look for a Partial badge. The client might have paid less than the balance, for example without the late fee that was added (see [Partial payments](/help/payments/partial-payments)).

## 4. Did they pay the right invoice?

Clients with several invoices sometimes pay one that is not the one they meant. Check **Payments** for the customer and move the payment to the correct invoice.

## Backdate the payment

When you record the payment, set **Date received** to the day the money actually arrived. Late fees are only charged for days when a balance was outstanding, so a late fee added after the true payment date is reduced or removed at the next nightly run (see [Late fees](/help/billing/late-fees)). A fee that was added and that you want to cancel can be waived (see [Waive or reduce a late fee](/help/billing/waive-or-reduce-a-late-fee)).

## Stop the emails meanwhile

If the client is annoyed by reminders, pause reminders for the customer (see [Pause reminders for a customer](/help/reminders/pause-reminders-for-a-customer)). Late fees continue unless the invoice is marked Disputed.

## Still unsure

Open a ticket with the invoice number, the date and the amount, and ask the client for a bank reference (see [Contact support](/help/troubleshooting/contact-support)).
