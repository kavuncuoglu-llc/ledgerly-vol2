---
title: Reminder delivery problems
category: reminders
summary: Lists the common reasons a client does not receive a reminder and how to check and fix each one.
updated: 2025-10-14
---

If a client says they never got a reminder, check the points below in order. Most problems are on the recipient or schedule side rather than a failure to send.

## Check that a reminder was due

Open the invoice and look at **Activity**. Reminders are sent at 3, 7 and 14 days past due, so an invoice that is 2 days overdue has not had one yet. Also check whether reminders were stopped or paused: the invoice might be Disputed, on a payment plan or belong to a customer with paused reminders (see [Pause reminders for a customer](/help/reminders/pause-reminders-for-a-customer)).

## Check the recipients

Reminders go to contacts marked **Receives invoices** on the customer record (see [Customer contacts and CC](/help/customers/customer-contacts-and-cc)). If no contact is marked or the email address has a typo, the email is not delivered. Activity shows a Bounced label when the address was rejected.

## Check the status in Activity

| Label | Meaning |
| --- | --- |
| Sent | Handed to the recipient's mail server |
| Delivered | Accepted by the mailbox |
| Opened | Client opened the email |
| Bounced | Address does not exist or mailbox is full |
| Blocked | Rejected as spam by the recipient's server |

## Blocked or in spam

Ask the client to check their junk folder and to add `invoices@example.com` to their safe sender list. Pro and Enterprise workspaces can send from their own domain, which improves delivery (see [Send from your own domain](/help/integrations/send-from-your-own-domain)).

## Resend

Use **Send reminder** to send a fresh email, optionally to a new address (see [Send a manual reminder](/help/reminders/send-a-manual-reminder)). Another route is to copy the pay link from the invoice and send it yourself (see [Pay link for clients](/help/payments/pay-link-for-clients)).

## Still not working

If the activity shows Delivered but the client does not see it, or Ledgerly shows no attempt although a reminder was due, contact support with the invoice number (see [Contact support](/help/troubleshooting/contact-support)).
