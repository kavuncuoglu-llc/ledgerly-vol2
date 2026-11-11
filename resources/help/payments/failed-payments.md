---
title: Failed payments
category: payments
summary: Explains why a client's card or bank payment can fail, who is notified and what happens to the invoice.
updated: 2025-09-17
---

A failed payment is an attempt by your client to pay an invoice through the pay link that did not succeed. This page is about your clients' payments. For a failed charge on your own Ledgerly subscription, see [Failed subscription payment](/help/billing/failed-subscription-payment).

## What happens

1. The client sees the reason given by their bank and can try again straight away with the same or another method.
2. You receive an in-app notification and an email titled "Payment failed", unless you turned it off in [Notification settings](/help/account/notification-settings).
3. The invoice stays in its current status. Nothing is recorded as paid, and reminders and late fees continue as normal.
4. The attempt appears in **Payments > Failed** with the reason code.

Ledgerly does not retry client payments on its own, because the client has to confirm each attempt.

## Common reasons

| Reason | What to tell the client |
| --- | --- |
| Insufficient funds | Try another card or account |
| Card declined | Contact the card issuer or use another card |
| Expired card | Use a card with a valid expiry date |
| Authentication failed | Complete the bank's verification step |
| ACH returned | Check the account number and balance (see [ACH bank transfers](/help/payments/ach-bank-transfers)) |
| Currency not supported by the card | Use a card that supports the invoice currency |

## If the client says they paid

Check **Payments > Failed** before assuming the payment arrived. If the money left their account but the invoice is still unpaid, see [Client says they paid but the invoice is unpaid](/help/troubleshooting/client-says-paid-but-unpaid) and [Payment stuck pending](/help/troubleshooting/payment-stuck-pending).

## Helping the client pay

Resend the pay link with **Send reminder** (see [Send a manual reminder](/help/reminders/send-a-manual-reminder)), offer a bank transfer or agree on a [payment plan](/help/payments/payment-plans).

## Returned ACH fee

A returned ACH payment costs you $4.00. Other failed attempts are free.

## Fees you pay

Processing fees apply only to successful payments. See [Payment processing fees](/help/payments/payment-processing-fees).
