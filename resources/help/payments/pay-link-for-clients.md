---
title: Pay link for clients
category: payments
summary: Describes the page your client sees when they open an invoice and what they can do from it.
updated: 2025-05-22
---

Every invoice email contains a link to a secure page that shows the invoice and, if you have turned on online payments, a Pay now button. Clients do not need a Ledgerly account.

## What the client sees

- The invoice with your logo, line items, tax and total
- The status label (Sent, Overdue or Paid) and the due date
- The late fee line, if one has been added
- A **Download PDF** button
- A **Pay now** button when LedgerlyPay is active (see [Accepting card payments](/help/payments/accepting-card-payments))
- A **Dispute this invoice** link (see [Disputing a charge](/help/billing/disputes))

When the invoice is Paid, the page shows a receipt instead. A Void invoice shows a notice that it was cancelled.

## Paying

The client chooses card or, for US dollar invoices, bank transfer. If partial payments are allowed, they can change the amount. After payment, the page confirms and Ledgerly emails a receipt (see [Payment receipts](/help/payments/payment-receipts)).

## Link security

The link contains a random token and works without logging in. Anyone who has the link can see the invoice, so only send it to the intended contact. You can generate a new link at any time with **More > Reset pay link**, which makes the old one stop working.

## Viewing

The first time the client opens the page, the invoice gets a Viewed badge and you see the date. A Viewed badge does not change the status.

## Customer portal

Clients who have several invoices can be given a portal that lists them all in one place (see [Customer portal](/help/customers/customer-portal)).

## Language and currency

The page appears in the language of the invoice and shows the invoice currency. The client's browser setting is used for date format.

## Problems

If the link is not working, see [Client did not receive the email](/help/troubleshooting/client-did-not-receive-email) and [Failed payments](/help/payments/failed-payments).
