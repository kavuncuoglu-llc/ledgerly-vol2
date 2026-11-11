---
title: Client did not receive the email
category: troubleshooting
summary: Helps you find out why a client did not get an invoice, receipt or reminder email and how to get it to them.
updated: 2025-04-28
---

When a client says the email never arrived, the cause is usually the address or the spam folder. The invoice itself is always available at its pay link, so you can unblock the client straight away.

## Quick fix: give them the link

Open the invoice, choose **Copy pay link**, and send it by chat or another email. They can view, download and pay from there (see [Pay link for clients](/help/payments/pay-link-for-clients)).

## Find out what happened

Open the invoice and look at **Activity**. Each email has a label:

- **Delivered**: it reached the mailbox. Ask the client to check spam, promotions or a shared inbox.
- **Opened**: they have seen it, and may be mistaking it for something else.
- **Bounced**: the address is wrong or the mailbox is full. Correct it on the customer (see [Customer contacts and CC](/help/customers/customer-contacts-and-cc)) and resend.
- **Blocked**: the recipient's server rejected it as spam.
- **No entry**: nothing was sent. The invoice might still be a Draft, or no contact is marked Receives invoices.

## Fix delivery for the long term

- Ask the client to add `invoices@example.com` to their contacts.
- On Pro and Enterprise, send from your own domain (see [Send from your own domain](/help/integrations/send-from-your-own-domain)).
- Avoid very large attachments, and use the pay link instead of the PDF if the client has strict filters.

## Reminders and receipts

Reminders and receipts follow the same rules (see [Reminder delivery problems](/help/reminders/reminder-delivery-problems) and [Payment receipts](/help/payments/payment-receipts)).

## When a client says they were never told

If a client says they never knew an invoice was overdue, check the Activity log. It shows when the invoice was sent, viewed, reminded and when a late fee was added (see [Late fees](/help/billing/late-fees)). If there is a bounce, correct the address and consider waiving the fee (see [Waive or reduce a late fee](/help/billing/waive-or-reduce-a-late-fee)).

## Still missing

Contact support with the invoice number and the client's address, and we can look at the delivery logs (see [Contact support](/help/troubleshooting/contact-support)).
